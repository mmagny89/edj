#!/usr/bin/env bash
# sauvegarde-locale — sauvegarde la base de DÉVELOPPEMENT (pg_dump -Fc) hors du dépôt, la restaure
# dans une base jetable, puis compare les effectifs table par table : une sauvegarde jamais relue
# n'est qu'une hypothèse.
#
#   Usage : outils/sauvegarde-locale.sh [dossier]    (défaut : ~/Sauvegardes/<nom du dossier du projet>)
#
# Généralisée à partir du script d'un projet (qui comptait trois tables écrites en dur) : la
# comparaison porte sur TOUTES les tables du schéma `public`, quel que soit le projet.
#
# À lancer avant toute opération qui touche au volume de la base (montée de version majeure de
# PostgreSQL comprise) : jamais `docker compose down --volumes` sur un environnement qui porte
# des données. Pour le serveur (préproduction, production), voir sauvegarder.sh et
# tester-restauration.sh.
#
# Périmètre : PostgreSQL derrière le service compose `database` (le stack de l'agence). MariaDB
# n'est pas géré ici : le script s'arrête avec un message, sans rien écrire.
set -uo pipefail

cd "$(dirname "$0")/.." || exit 1
projet=$(basename "$PWD")
dossier=${1:-$HOME/Sauvegardes/$projet}
base_jetable=restauration_essai
journal=$(mktemp)
trap 'rm -f "$journal"' EXIT

dc() { docker compose exec -T database sh -c "$1"; }

dc 'command -v pg_dump > /dev/null' 2> /dev/null \
	|| { echo "sauvegarde-locale : pas de PostgreSQL derrière le service « database » (stack lancé ? MariaDB n'est pas géré)." >&2; exit 2; }

mkdir -p "$dossier" || exit 1
fichier="$dossier/$projet-$(date +%Y%m%d-%H%M%S).dump"

echo "── Sauvegarde : $fichier"
dc 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$fichier" \
	|| { echo "ÉCHEC pg_dump" >&2; rm -f "$fichier"; exit 1; }
[ -s "$fichier" ] || { echo "ÉCHEC : sauvegarde vide" >&2; rm -f "$fichier"; exit 1; }
echo "   $(du -h "$fichier" | cut -f1)"

echo "── Restauration d'essai dans une base jetable"
dc "psql -U \"\$POSTGRES_USER\" -d postgres -qc 'SET client_min_messages = warning' -c 'DROP DATABASE IF EXISTS $base_jetable WITH (FORCE)' -c 'CREATE DATABASE $base_jetable'" > /dev/null || exit 1
dc "pg_restore -U \"\$POSTGRES_USER\" -d $base_jetable --no-owner" < "$fichier" > "$journal" 2>&1 \
	|| { echo "ÉCHEC pg_restore :" >&2; tail -n 5 "$journal" >&2; }

# Effectif exact de chaque table du schéma public, une ligne « table=n » par table.
compter="SELECT table_name || '=' || (xpath('/row/c/text()', query_to_xml(format('select count(*) as c from %I.%I', table_schema, table_name), false, true, '')))[1]::text FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name"
origine=$(dc "psql -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -tAc \"$compter\"")
copie=$(dc "psql -U \"\$POSTGRES_USER\" -d $base_jetable -tAc \"$compter\"")
dc "psql -U \"\$POSTGRES_USER\" -d postgres -qc 'DROP DATABASE IF EXISTS $base_jetable WITH (FORCE)'" > /dev/null

nb=$(printf '%s\n' "$origine" | grep -c '=')
echo "   $nb tables comparées"
if [ "$nb" -gt 0 ] && [ "$origine" = "$copie" ]; then
	echo "── Sauvegarde relue avec succès."
	exit 0
fi

echo "── Les effectifs diffèrent : la sauvegarde n'est pas fiable." >&2
diff <(printf '%s\n' "$origine") <(printf '%s\n' "$copie") | sed 's/^/   /' >&2
exit 1
