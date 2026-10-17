#!/bin/sh
# sauvegarder.sh — sauvegarde de la base de donnees, a lancer SUR LE SERVEUR.
#
#   sh outils/sauvegarder.sh prod              # vers /srv/sauvegardes/edj-prod
#   sh outils/sauvegarder.sh prod /autre/dir   # vers un autre dossier
#
# La base porte les bulletins d'adhesion, donc des donnees personnelles — dont
# celles de mineurs — et les comptes d'administration. Une sauvegarde est a la
# fois la seule protection contre une perte et un second endroit ou ces donnees
# existent : le dossier est donc ferme au monde (0700) et les fichiers aussi
# (0600).
#
# Un dump est vide s'il echoue en cours de route, sans que le shell le dise :
# le fichier est donc controle (archive lisible, contenu non vide, presence des
# tables) avant d'etre garde, et supprime sinon.
#
# Conservation : les 14 plus recentes (RETENTION=n pour changer). Une
# sauvegarde par jour suffit pour une association ; la lancer par cron :
#
#   17 3 * * * cd /srv/edj-prod && sh outils/sauvegarder.sh prod >> /var/log/edj-sauvegarde.log 2>&1
#
# Une sauvegarde qui reste sur le serveur ne protege pas de la perte du serveur :
# la copier AILLEURS (autre machine, stockage objet) est a la charge de
# l'exploitant, et un dump jamais restaure n'est pas une sauvegarde — voir
# tester-restauration.sh.
set -eu

ENVIRONNEMENT=${1:-}
case "$ENVIRONNEMENT" in
	prod|staging) COMPOSE="docker compose -f compose.yml -f compose.${ENVIRONNEMENT}.yml --env-file .env.${ENVIRONNEMENT}.local" ;;
	dev)          COMPOSE="docker compose" ;;
	*)
		echo "Usage : $0 prod|staging|dev [dossier]" >&2
		exit 1
		;;
esac

PROJET=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$PROJET"

DOSSIER=${2:-/srv/sauvegardes/edj-$ENVIRONNEMENT}
RETENTION=${RETENTION:-14}

journal() {
	echo "[$(date '+%F %T')] $*"
}

# Utilisateur et base viennent de la configuration du service : jamais d'un
# nom ecrit en dur ici, qui divergerait du stack.
UTILISATEUR=$($COMPOSE exec -T database printenv POSTGRES_USER)
BASE=$($COMPOSE exec -T database printenv POSTGRES_DB)

umask 077
mkdir -p "$DOSSIER"
chmod 700 "$DOSSIER"

FICHIER="$DOSSIER/edj-$ENVIRONNEMENT-$(date '+%Y%m%d-%H%M%S').sql.gz"
journal "Sauvegarde de $BASE vers $FICHIER"

# --clean --if-exists : la restauration repart d'un schema propre.
if ! $COMPOSE exec -T database pg_dump -U "$UTILISATEUR" -d "$BASE" --clean --if-exists --no-owner | gzip > "$FICHIER"; then
	journal "ECHEC : pg_dump a echoue."
	rm -f "$FICHIER"
	exit 1
fi

if ! gzip -t "$FICHIER" 2>/dev/null; then
	journal "ECHEC : l'archive est illisible."
	rm -f "$FICHIER"
	exit 1
fi

if ! gzip -dc "$FICHIER" | grep -q 'CREATE TABLE public.event'; then
	journal "ECHEC : la sauvegarde ne contient pas la table des evenements — dump vide ou tronque."
	rm -f "$FICHIER"
	exit 1
fi

journal "OK : $(du -h "$FICHIER" | cut -f1)"

# Rotation : on garde les plus recentes, on supprime le reste.
ANCIENNES=$(ls -1t "$DOSSIER"/edj-"$ENVIRONNEMENT"-*.sql.gz 2>/dev/null | tail -n +$((RETENTION + 1)) || true)
if [ -n "$ANCIENNES" ]; then
	echo "$ANCIENNES" | while read -r ancienne; do
		rm -f "$ancienne"
		journal "Supprime (au-dela de $RETENTION) : $ancienne"
	done
fi

journal "Sauvegardes conservees : $(ls -1 "$DOSSIER"/edj-"$ENVIRONNEMENT"-*.sql.gz | wc -l | tr -d ' ')"
