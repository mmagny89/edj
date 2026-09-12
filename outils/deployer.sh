#!/bin/sh
# deployer.sh — deploiement d'un environnement, execute SUR LE SERVEUR.
#
# Appele par GitHub Actions a travers une forced command SSH. L'argument vient
# de authorized_keys, donc du serveur, jamais du client : rien de ce que GitHub
# envoie n'influence ce qui est execute ici.
#
#   command="/docker/<projet>-prod/outils/deployer.sh prod",no-agent-forwarding,no-port-forwarding,no-pty,no-user-rc,no-X11-forwarding ssh-ed25519 AAAA... deploiement-github-<projet>
#
# La logique reste ici et non dans le workflow (conventions, section 22) : les
# chemins et les secrets ne quittent pas le serveur, et la cle confiee a GitHub
# ne peut rien lancer d'autre.
set -eu

ENVIRONNEMENT=${1:-}

case "$ENVIRONNEMENT" in
	prod)    BRANCHE=main ;;
	staging) BRANCHE=develop ;;
	*)
		echo "Usage : $0 prod|staging" >&2
		exit 1
		;;
esac

# Le projet se deduit de l'emplacement du script, jamais d'un chemin en dur :
# renommer ou deplacer le clone ne casse alors que la forced command, qui porte
# de toute facon le chemin absolu (conventions, section 22b).
PROJET=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
SECRETS="$PROJET/.env.${ENVIRONNEMENT}.local"
COMPOSE="docker compose -f compose.yml -f compose.${ENVIRONNEMENT}.yml --env-file $SECRETS"

cd "$PROJET"

journal() {
	echo "[$(date '+%F %T')] $*"
}

journal "Deploiement $ENVIRONNEMENT depuis $BRANCHE, dans $PROJET"

if [ ! -f "$SECRETS" ]; then
	journal "ECHEC : $SECRETS absent. Le renseigner avant tout deploiement."
	exit 1
fi

# Se deployer depuis la mauvaise branche passerait inapercu : la verification
# coute une ligne et evite de livrer la recette en production.
ACTUELLE=$(git rev-parse --abbrev-ref HEAD)
if [ "$ACTUELLE" != "$BRANCHE" ]; then
	journal "ECHEC : ce clone est sur '$ACTUELLE', or $ENVIRONNEMENT se deploie depuis '$BRANCHE'."
	exit 1
fi

# --ff-only : un deploiement ne fusionne rien et ne resout aucun conflit. Si
# l'avance rapide est impossible, quelqu'un a modifie le clone du serveur, et
# c'est a un humain de regarder.
journal "Recuperation du code"
git fetch --quiet origin "$BRANCHE"
git merge --ff-only "origin/$BRANCHE"
journal "Version deployee : $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"

journal "Construction et demarrage"
$COMPOSE up -d --build --wait

# RUN_MIGRATIONS=0 en production (section 8) : la mise a jour de schema est une
# etape explicite, jamais le demarrage du conteneur.
journal "Mise a jour du schema"
$COMPOSE exec -T php php bin/console doctrine:migrations:migrate --no-interaction

# Sans lui, chaque deploiement laisse l'image precedente sans etiquette et le
# disque du serveur se remplit en silence.
journal "Menage des images orphelines"
docker image prune -f >/dev/null

journal "Etat des conteneurs"
$COMPOSE ps --format '  {{.Name}}  {{.Status}}'

# Le conteneur peut se declarer healthy alors que le site est inaccessible
# derriere le proxy : le controle qui compte se fait depuis l'exterieur.
#
# En prod, SERVER_NAME est fige a ":80" (Traefik termine le TLS) et le domaine
# vit dans SERVER_HOST ; en staging, Caddy sert le domaine lui-meme et c'est
# SERVER_NAME qui le porte. On lit donc l'un puis l'autre.
lire_secret() {
	grep -E "^$1=" "$SECRETS" 2>/dev/null | tail -n 1 | cut -d= -f2- | tr -d '"'"'"''
}

DOMAINE=$(lire_secret SERVER_HOST)
[ -n "$DOMAINE" ] || DOMAINE=$(lire_secret SERVER_NAME)

case "$DOMAINE" in
	''|:*) journal "Aucun domaine exploitable dans $SECRETS — verification externe ignoree" ;;
	*)
		CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "https://$DOMAINE/" || echo 000)
		journal "https://$DOMAINE/ repond $CODE"
		case "$CODE" in
			2*|3*) ;;
			*)
				journal "ECHEC : le site ne repond pas correctement depuis l'exterieur."
				exit 1
				;;
		esac
		;;
esac

journal "Deploiement $ENVIRONNEMENT termine"
