#!/bin/sh
# tester-restauration.sh — prouve qu'une sauvegarde se restaure.
#
#   sh outils/tester-restauration.sh sauvegardes/<fichier>.dump|.sql.gz
#
# Restaure le fichier dans une base jetable (conteneur ephemere, aucun port
# publie, aucun lien avec la base du projet) puis controle qu'elle est
# exploitable : tables presentes et, quand l'application tient un journal de
# migrations Doctrine, dernier jalon identique a celui du code. Le conteneur est
# supprime a la sortie, succes ou non. Le moteur se deduit du fichier.
#
# A rejouer apres chaque changement de version du moteur et regulierement sur
# une sauvegarde de production : une sauvegarde jamais restauree n'est qu'une
# hypothese.
set -eu

FICHIER=${1:-}
[ -n "$FICHIER" ] && [ -f "$FICHIER" ] || { echo "Usage : sh $0 <fichier.dump|fichier.sql.gz>" >&2; exit 1; }

RACINE=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$RACINE"

NOM="restauration-test-$$"
trap 'docker rm -f "$NOM" > /dev/null 2>&1 || true' EXIT

# Meme version majeure que la base du projet : le .env racine en est la source.
case "$FICHIER" in
	*.dump)
		VERSION=$(sed -n 's/^POSTGRES_VERSION=//p' .env)
		[ -n "$VERSION" ] || { echo "POSTGRES_VERSION introuvable dans .env." >&2; exit 1; }
		docker run -d --name "$NOM" -e POSTGRES_PASSWORD=jetable -e POSTGRES_USER=app -e POSTGRES_DB=restauration \
			"postgres:${VERSION}-alpine" > /dev/null
		attente=0
		until docker exec "$NOM" pg_isready -U app -d restauration > /dev/null 2>&1; do
			attente=$((attente + 1))
			[ "$attente" -le 60 ] || { echo "PostgreSQL jetable ne demarre pas." >&2; exit 1; }
			sleep 1
		done
		# Le premier demarrage de l'image redemarre le serveur apres l'initialisation.
		sleep 2
		docker exec "$NOM" pg_isready -U app -d restauration > /dev/null
		docker exec -i "$NOM" pg_restore --exit-on-error --no-owner --username app --dbname restauration < "$FICHIER"
		sql() { docker exec "$NOM" psql -U app -d restauration -tA -c "$1"; }
		TABLES=$(sql "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'")
		JOURNAL=$(sql "SELECT to_regclass('public.doctrine_migration_versions') IS NOT NULL")
		[ "$JOURNAL" = t ] && DERNIERE=$(sql "SELECT version FROM doctrine_migration_versions ORDER BY version DESC LIMIT 1") || DERNIERE=""
		;;
	*.sql.gz)
		VERSION=$(sed -n 's/^MARIADB_VERSION=//p' .env)
		[ -n "$VERSION" ] || { echo "MARIADB_VERSION introuvable dans .env." >&2; exit 1; }
		docker run -d --name "$NOM" -e MARIADB_ROOT_PASSWORD=jetable -e MARIADB_DATABASE=restauration "mariadb:${VERSION}" > /dev/null
		attente=0
		until docker exec "$NOM" mariadb -uroot -pjetable -e 'SELECT 1' > /dev/null 2>&1; do
			attente=$((attente + 1))
			[ "$attente" -le 90 ] || { echo "MariaDB jetable ne demarre pas." >&2; exit 1; }
			sleep 1
		done
		gzip -dc "$FICHIER" | docker exec -i "$NOM" mariadb -uroot -pjetable restauration
		sql() { docker exec "$NOM" mariadb -uroot -pjetable restauration -N -B -e "$1"; }
		TABLES=$(sql "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'restauration'")
		DERNIERE=""
		;;
	*)
		echo "Extension inconnue : attendu .dump (PostgreSQL) ou .sql.gz (MariaDB)." >&2
		exit 1
		;;
esac

[ "$TABLES" -gt 0 ] || { echo "Aucune table restauree." >&2; exit 1; }
echo "Tables restaurees : $TABLES"

# Alignement avec le code : seulement si la base tient un journal Doctrine ET que le code porte des migrations.
if [ -n "$DERNIERE" ] && ls app/migrations/Version*.php > /dev/null 2>&1; then
	ATTENDUE=$(ls app/migrations/Version*.php | sed 's#.*/##; s#\.php$##' | sort | tail -n 1)
	echo "Derniere migration restauree : $DERNIERE"
	case "$DERNIERE" in
		*"$ATTENDUE") echo "Restauration reussie : la base correspond au code." ;;
		*)
			echo "ATTENTION : la sauvegarde est anterieure au code (attendue : $ATTENDUE)." >&2
			echo "Elle est restaurable mais ne contient pas les dernieres migrations." >&2
			exit 2
			;;
	esac
else
	echo "Restauration reussie."
fi
