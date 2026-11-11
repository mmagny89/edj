#!/bin/sh
# sauvegarder.sh — sauvegarde la base de donnees et la copie hors du serveur.
#
#   sh outils/sauvegarder.sh dev|staging|prod
#
# Produit sauvegardes/<projet>-<env>-<AAAAMMJJ-HHMMSS>.dump (PostgreSQL, format
# custom de pg_dump) ou .sql.gz (MariaDB), le verifie, puis le copie vers
# BACKUP_REMOTE par rsync sur SSH. Le moteur se deduit du .env racine
# (POSTGRES_VERSION ou MARIADB_VERSION). Le serveur qui porte la base ne doit
# pas etre le seul a porter sa sauvegarde : staging et prod REFUSENT de continuer
# sans BACKUP_REMOTE.
#
# Variables d'environnement (a poser dans la crontab ou le service, jamais dans
# un fichier committe) :
#   BACKUP_REMOTE     destination rsync, ex. sauvegarde@hote:/srv/sauvegardes/projet/
#   BACKUP_DIR        dossier local (defaut : sauvegardes/ a la racine du projet)
#   BACKUP_KEEP_DAYS  anciennete des copies locales conservees (defaut : 14)
#
# La sauvegarde contient des donnees personnelles : le dossier local est en
# 700, les fichiers en 600, et la destination doit etre chiffree au repos
# (RGPD, art. 32). La retention cote destination releve de la destination.
#
# Ne couvre PAS les volumes de fichiers (pieces deposees par les utilisateurs) :
# voir README.docker.md, section « Sauvegardes ».
set -eu

ENVIRONNEMENT=${1:-}
case "$ENVIRONNEMENT" in
	dev | staging | prod) ;;
	*)
		echo "Usage : sh $0 dev|staging|prod" >&2
		exit 1
		;;
esac

RACINE=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$RACINE"
PROJET=$(basename "$RACINE" | tr '[:upper:]' '[:lower:]')

if grep -q '^POSTGRES_VERSION=' .env; then
	MOTEUR=postgres
	EXTENSION=dump
elif grep -q '^MARIADB_VERSION=' .env; then
	MOTEUR=mariadb
	EXTENSION=sql.gz
else
	echo "Moteur introuvable : ni POSTGRES_VERSION ni MARIADB_VERSION dans .env." >&2
	exit 1
fi

if [ "$ENVIRONNEMENT" = dev ]; then
	COMPOSE="docker compose"
else
	[ -f ".env.${ENVIRONNEMENT}.local" ] || { echo ".env.${ENVIRONNEMENT}.local introuvable — lancer depuis le clone de l'environnement." >&2; exit 1; }
	COMPOSE="docker compose -f compose.yml -f compose.${ENVIRONNEMENT}.yml --env-file .env.${ENVIRONNEMENT}.local"
fi

if [ "$ENVIRONNEMENT" != dev ] && [ -z "${BACKUP_REMOTE:-}" ]; then
	echo "BACKUP_REMOTE manquant : une sauvegarde qui ne quitte pas le serveur ne protege pas de sa perte." >&2
	exit 1
fi

DOSSIER=${BACKUP_DIR:-"$RACINE/sauvegardes"}
GARDER=${BACKUP_KEEP_DAYS:-14}
mkdir -p "$DOSSIER"
chmod 700 "$DOSSIER"

FICHIER="$DOSSIER/${PROJET}-${ENVIRONNEMENT}-$(date -u +%Y%m%d-%H%M%S).${EXTENSION}"
PARTIEL="$FICHIER.partiel"
trap 'rm -f "$PARTIEL"' EXIT

# Les identifiants sont lus DANS le conteneur : ils n'apparaissent ni dans la
# ligne de commande ni dans l'historique du shell.
umask 077
if [ "$MOTEUR" = postgres ]; then
	$COMPOSE exec -T database sh -c 'pg_dump --format=custom --no-owner --username "$POSTGRES_USER" "$POSTGRES_DB"' > "$PARTIEL"
	# Une sauvegarde non lisible est pire que pas de sauvegarde : elle rassure.
	controle() { $COMPOSE exec -T database pg_restore --list < "$PARTIEL" > /dev/null; }
else
	$COMPOSE exec -T database sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb-dump --single-transaction --routines --events --user "$MARIADB_USER" "$MARIADB_DATABASE"' | gzip -c > "$PARTIEL"
	# mariadb-dump termine un export complet par cette ligne : son absence trahit une coupure.
	controle() { gzip -t "$PARTIEL" && gzip -dc "$PARTIEL" | tail -n 3 | grep -q 'Dump completed'; }
fi

if ! controle; then
	echo "Sauvegarde illisible, abandon (rien n'est conserve)." >&2
	exit 1
fi
mv "$PARTIEL" "$FICHIER"
echo "Sauvegarde : $FICHIER ($(wc -c < "$FICHIER" | tr -d ' ') octets)"

if [ -n "${BACKUP_REMOTE:-}" ]; then
	rsync -e "ssh -o BatchMode=yes" --chmod=F600 "$FICHIER" "$BACKUP_REMOTE"
	echo "Copiee vers $BACKUP_REMOTE"
else
	echo "BACKUP_REMOTE non defini (dev) : copie locale seulement." >&2
fi

find "$DOSSIER" -name "${PROJET}-${ENVIRONNEMENT}-*.${EXTENSION}" -type f -mtime "+$GARDER" -exec rm -f {} +
