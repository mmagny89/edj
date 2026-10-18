#!/bin/sh
# sauvegarder-serveur.sh — sauvegarde la base PostgreSQL de TOUS les projets d'un
# serveur, a lancer SUR LE SERVEUR, par root, une fois par jour (cron).
#
#   BACKUP_PASSPHRASE_FILE=/root/.sauvegarde-phrase sh sauvegarder-serveur.sh
#
# Un projet = un dossier <RACINE>/<nom>-prod avec un conteneur <nom>-prod-database.
# Chaque base est exportee (pg_dump), compressee, chiffree en AES-256, verifiee
# (l'archive se relit et finit par la ligne de fin de pg_dump) puis gardee dans
# <DEST>/<projet>/. Le dump ne touche jamais le disque en clair.
#
# Variables :
#   BACKUP_PASSPHRASE_FILE  OBLIGATOIRE. Fichier 0400 portant la phrase. La garder AUSSI
#                           dans un gestionnaire de mots de passe : sans elle, aucune
#                           sauvegarde ne se relit. Ne jamais la ranger a cote des archives.
#   RACINE      dossier des projets             (defaut : /docker)
#   DEST        dossier des sauvegardes         (defaut : /srv/sauvegardes)
#   RETENTION   copies gardees par projet       (defaut : 14)
#   EXCLURE     projets a ne pas traiter, separes par des espaces (ex. "sdj-prod", qui est
#               en SQLite et a sa propre commande). Un projet exclu est NOMME au journal ;
#               un projet qui disparait sans etre exclu fait echouer le script.
#   BACKUP_REMOTE  destination rsync (ex. utilisateur@hote:/chemin/) : copie hors du
#                  serveur. Sans elle le script avertit, car une sauvegarde qui reste
#                  sur le serveur ne protege pas de sa perte.
#
# Code de sortie non nul si UN SEUL projet echoue : le cron le dit, il ne se tait pas.
set -eu

RACINE=${RACINE:-/docker}
DEST=${DEST:-/srv/sauvegardes}
RETENTION=${RETENTION:-14}
PHRASE=${BACKUP_PASSPHRASE_FILE:-}
EXCLURE=${EXCLURE:-}

journal() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }

if [ -z "$PHRASE" ] || [ ! -s "$PHRASE" ]; then
	journal "ECHEC : BACKUP_PASSPHRASE_FILE absent ou vide. Rien n'est sauvegarde en clair."
	exit 1
fi

umask 077
mkdir -p "$DEST"
chmod 700 "$DEST"
ECHECS=0
RESULTATS=""

for dossier in "$RACINE"/*-prod; do
	[ -d "$dossier" ] || continue
	projet=$(basename "$dossier")
	conteneur="${projet}-database"

	case " $EXCLURE " in
		*" $projet "*)
			journal "Ignore $projet : exclu par EXCLURE."
			continue
			;;
	esac

	if [ "$(docker inspect -f '{{.State.Running}}' "$conteneur" 2>/dev/null || true)" != true ]; then
		journal "ECHEC $projet : conteneur $conteneur absent ou arrete."
		ECHECS=$((ECHECS + 1))
		continue
	fi

	cible="$DEST/$projet"
	mkdir -p "$cible"
	chmod 700 "$cible"
	fichier="$cible/$projet-$(date -u +%Y%m%d-%H%M%S).sql.gz.enc"
	partiel="$fichier.partiel"

	# Les identifiants se lisent DANS le conteneur : ni ligne de commande, ni historique.
	if ! docker exec "$conteneur" sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists --no-owner' |
		gzip | openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -salt -pass "file:$PHRASE" -out "$partiel"; then
		rm -f "$partiel"
		journal "ECHEC $projet : pg_dump ou chiffrement."
		ECHECS=$((ECHECS + 1))
		continue
	fi

	# Une archive qui ne se relit pas rassure sans proteger : on la relit avant de la garder.
	# pg_dump 17.6+ ajoute des lignes apres la phrase de fin (\unrestrict) : on regarde les dix dernieres.
	if ! openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -pass "file:$PHRASE" -in "$partiel" | gzip -dc | tail -n 10 | grep -q 'PostgreSQL database dump complete'; then
		rm -f "$partiel"
		journal "ECHEC $projet : archive illisible ou incomplete, rien n'est garde."
		ECHECS=$((ECHECS + 1))
		continue
	fi
	mv "$partiel" "$fichier"
	journal "OK $projet : $fichier ($(wc -c <"$fichier" | tr -d ' ') octets)"

	if [ -n "${BACKUP_REMOTE:-}" ]; then
		if rsync -e "ssh -o BatchMode=yes" --chmod=F600 "$fichier" "$BACKUP_REMOTE"; then
			journal "OK $projet : copiee vers $BACKUP_REMOTE"
		else
			journal "ECHEC $projet : copie vers $BACKUP_REMOTE."
			ECHECS=$((ECHECS + 1))
		fi
	fi

	# shellcheck disable=SC2012
	ls -1t "$cible"/*.sql.gz.enc 2>/dev/null | tail -n +$((RETENTION + 1)) | while read -r ancienne; do
		rm -f "$ancienne"
		journal "Supprime (au-dela de $RETENTION) : $ancienne"
	done
done

[ -n "${BACKUP_REMOTE:-}" ] || journal "AVERTISSEMENT : BACKUP_REMOTE non defini, les sauvegardes restent sur ce serveur."

if [ "$ECHECS" -gt 0 ]; then
	journal "$ECHECS echec(s)."
	exit 1
fi
journal "Toutes les sauvegardes sont faites."
