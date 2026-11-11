#!/usr/bin/env bash
# ci-local — rejoue les portes de .github/workflows/qualite.yml dans le conteneur php, avec
# l'environnement MINIMAL de la CI (`env -i` : aucune des variables que compose.yml injecte) et
# une base jetable. C'est ce qui révèle une variable oubliée dans le workflow, ce que `make test`
# ne voit jamais puisque le conteneur en porte déjà tout.
#
#   Usage : outils/ci-local.sh        (le stack dev doit tourner : docker compose up -d --wait)
#
# Généralisée à partir du script d'un projet : l'environnement est LU dans le bloc `env:` du
# workflow (ni plus, ni moins que ce que la CI déclare), et chaque porte s'ignore, visiblement,
# quand son outil est absent — comme dans le workflow généré. Une porte qui ne tourne pas ici
# n'est jamais comptée comme réussie.
#
# Rien n'est écrit dans la base de développement : la base `app_ci` est créée puis supprimée.
# Les portes du front (npm) se rejouent à part : `docker compose --profile frontend exec node …`.
set -uo pipefail

cd "$(dirname "$0")/.." || exit 1
projet=$(basename "$PWD")
workflow=.github/workflows/qualite.yml
[ -f "$workflow" ] || { echo "ci-local : $workflow introuvable (projet sans portes qualité générées)." >&2; exit 2; }

php() { docker compose exec -T php "$@"; }

url_dev=$(php sh -c 'printf %s "$DATABASE_URL"') || { echo "ci-local : conteneur php injoignable (stack démarré ?)." >&2; exit 1; }
case "$url_dev" in
	*/app\?*) ;;
	*) echo "ci-local : DATABASE_URL inattendue (base « app » attendue, convention du stack)." >&2; exit 1 ;;
esac
url_ci=${url_dev/\/app\?/\/app_ci?}
chemin_conteneur=$(php sh -c 'printf %s "$PATH"')

# Variables du bloc `env:` de niveau 0 du workflow. PHP_VERSION ne concerne que le runner ;
# DATABASE_URL est remplacée par celle de la base jetable.
env_ci=()
while IFS= read -r ligne; do
	cle=${ligne%%:*}
	valeur=${ligne#*:}
	valeur=$(printf '%s' "$valeur" | sed -E "s/^[[:space:]]+//; s/^['\"]//; s/['\"]$//")
	case "$cle" in
		PHP_VERSION) continue ;;
		DATABASE_URL) valeur=$url_ci ;;
	esac
	env_ci+=("$cle=$valeur")
done < <(awk '/^env:/{e=1; next} e && /^[^ #]/{exit} e && /^  [A-Z_]+:/{sub(/^  /,""); print}' "$workflow")
env_ci+=("DATABASE_URL=$url_ci")

ci() { docker compose exec -T php env -i PATH="$chemin_conteneur" HOME=/tmp ${env_ci[@]+"${env_ci[@]}"} "$@"; }

journal=$(mktemp)
trap 'rm -f "$journal"' EXIT
echecs=0
ignorees=0

# porte <nom> <test de présence | -> <commande...>
porte() {
	local nom=$1 presence=$2
	shift 2
	if [ "$presence" != "-" ] && ! php sh -c "$presence" > /dev/null 2>&1; then
		echo "  ⊘  $nom — ignorée (outil ou fichier absent)"
		ignorees=$((ignorees + 1))
		return
	fi
	if "$@" > "$journal" 2>&1; then
		echo "  ok  $nom"
	else
		echo "  ÉCHEC  $nom"
		tail -n 25 "$journal" | sed 's/^/        /'
		echecs=$((echecs + 1))
	fi
}

echo "── Portes de la CI de $projet, environnement vierge"
porte "Cohérence du dépôt (composer validate)" '[ -f composer.json ]' ci composer validate --strict --no-check-publish
porte "Lints Symfony (yaml)" '[ -f bin/console ]' ci php bin/console lint:yaml config
porte "Lints Symfony (conteneur)" '[ -f bin/console ]' ci php bin/console lint:container
porte "Audit des dépendances" '[ -f composer.lock ]' ci composer audit --abandoned=report
porte "Style (PHP CS Fixer)" '[ -x vendor/bin/php-cs-fixer ] && ls .php-cs-fixer*.php > /dev/null 2>&1' ci vendor/bin/php-cs-fixer fix --dry-run --diff
porte "Analyse statique (PHPStan)" '[ -x vendor/bin/phpstan ]' ci sh -c 'APP_DEBUG=1 php bin/console cache:warmup --env=dev && vendor/bin/phpstan analyse --no-progress'
porte "Base de test créée et migrée" '[ -f bin/console ] && [ -d migrations ]' ci sh -c 'php bin/console doctrine:database:create --env=test --if-not-exists --no-interaction && php bin/console doctrine:migrations:migrate --env=test --no-interaction'
porte "Tests PHP (PHPUnit)" '[ -x vendor/bin/phpunit ]' ci vendor/bin/phpunit

# Nettoyage : la base jetable ne doit pas survivre à l'essai.
docker compose exec -T database sh -c 'psql -U "$POSTGRES_USER" -d postgres -qc "DROP DATABASE IF EXISTS app_ci WITH (FORCE)"' > /dev/null 2>&1

if [ "$echecs" -gt 0 ]; then
	echo "── $echecs porte(s) en échec."
	exit 1
fi
[ "$ignorees" -gt 0 ] && echo "── $ignorees porte(s) ignorée(s) : pas de verdict sur elles."
echo "── Toutes les portes exécutées passent."
