#!/bin/sh
# tester-restauration.sh — prouve qu'une sauvegarde se restaure, SANS toucher a
# la base en service.
#
#   sh outils/tester-restauration.sh prod                  # la plus recente
#   sh outils/tester-restauration.sh prod fichier.sql.gz   # une sauvegarde precise
#
# Un dump jamais restaure n'est pas une sauvegarde : c'est un fichier dont on
# espere qu'il marche. Ce script restaure dans une base JETABLE, au nom
# distinct, sur le meme serveur PostgreSQL, compte les lignes des tables qui
# comptent, puis supprime la base d'essai. La base en service n'est ni lue en
# ecriture ni modifiee.
#
# A rejouer apres chaque changement de version majeure de PostgreSQL et, au
# moins, tous les trimestres.
set -eu

ENVIRONNEMENT=${1:-}
case "$ENVIRONNEMENT" in
	prod|staging) COMPOSE="docker compose -f compose.yml -f compose.${ENVIRONNEMENT}.yml --env-file .env.${ENVIRONNEMENT}.local" ;;
	dev)          COMPOSE="docker compose" ;;
	*)
		echo "Usage : $0 prod|staging|dev [fichier.sql.gz]" >&2
		exit 1
		;;
esac

PROJET=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$PROJET"

DOSSIER=/srv/sauvegardes/edj-$ENVIRONNEMENT
FICHIER=${2:-}
if [ -z "$FICHIER" ]; then
	FICHIER=$(ls -1t "$DOSSIER"/edj-"$ENVIRONNEMENT"-*.sql.gz 2>/dev/null | head -n 1 || true)
fi
if [ -z "$FICHIER" ] || [ ! -f "$FICHIER" ]; then
	echo "Aucune sauvegarde a restaurer (cherche dans $DOSSIER)." >&2
	exit 1
fi

journal() {
	echo "[$(date '+%F %T')] $*"
}

UTILISATEUR=$($COMPOSE exec -T database printenv POSTGRES_USER)
BASE=$($COMPOSE exec -T database printenv POSTGRES_DB)
ESSAI="${BASE}_restauration_essai"

psql_admin() {
	$COMPOSE exec -T database psql -U "$UTILISATEUR" -d postgres -v ON_ERROR_STOP=1 "$@"
}

# Quoi qu'il arrive, la base d'essai disparait.
nettoyer() {
	psql_admin -c "DROP DATABASE IF EXISTS \"$ESSAI\"" >/dev/null 2>&1 || true
}
trap nettoyer EXIT

journal "Restauration de $FICHIER dans la base jetable $ESSAI"
psql_admin -c "DROP DATABASE IF EXISTS \"$ESSAI\"" >/dev/null
psql_admin -c "CREATE DATABASE \"$ESSAI\"" >/dev/null

if ! gzip -dc "$FICHIER" | $COMPOSE exec -T database psql -U "$UTILISATEUR" -d "$ESSAI" -v ON_ERROR_STOP=1 -q >/dev/null; then
	journal "ECHEC : la restauration a rencontre une erreur."
	exit 1
fi

compter() {
	$COMPOSE exec -T database psql -U "$UTILISATEUR" -d "$ESSAI" -At -c "SELECT count(*) FROM $1"
}

journal "Contenu restaure :"
for table in event membership_application '"user"'; do
	nombre=$(compter "$table") || { journal "ECHEC : la table $table manque."; exit 1; }
	journal "  $table : $nombre ligne(s)"
done

versions=$($COMPOSE exec -T database psql -U "$UTILISATEUR" -d "$ESSAI" -At -c "SELECT count(*) FROM doctrine_migration_versions")
journal "  migrations enregistrees : $versions"

journal "OK : la sauvegarde se restaure. Base d'essai supprimee."
