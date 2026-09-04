#!/bin/sh
# install-symfony — image dev uniquement, aucune option. Contrat fixe
# (conventions, section 7) : cree le squelette sans ecraser de fichier
# existant, neutralise le compose.yaml concurrent genere par Symfony Flex,
# installe les dependances, puis appelle neutralize-app-env. Idempotent :
# ne fait rien si l'application est deja installee.
set -e

cd /app

if [ -f composer.json ]; then
	echo "install-symfony: composer.json already present, application already installed, nothing to do." >&2
	exit 0
fi

# /app contient toujours au moins app/.gitkeep (manifeste, section 1) :
# composer create-project refuse un repertoire cible non vide. On compose
# le squelette dans un repertoire temporaire, puis on copie de facon non
# destructive (cp -Rn) vers /app.
TMP_SKELETON="$(mktemp -d)"
trap 'rm -rf "$TMP_SKELETON"' EXIT

# --no-install : composer.json doit exister et porter le flag ci-dessous
# AVANT le premier "composer install", sans quoi la recette symfony/docker
# de Flex s'execute une fois et depose son propre compose.yaml concurrent.
composer create-project symfony/skeleton "$TMP_SKELETON" --no-interaction --no-progress --no-install

cp -Rn "$TMP_SKELETON"/. /app/

composer config --json extra.symfony.docker 'false'

composer install --no-interaction --no-progress

# Ajoute par .claude/scripts/setup-symfony.sh : rendu Twig + TailwindCSS via
# AssetMapper (pas de Node cote PHP, coherent avec le reste du stack).
composer require --no-interaction webapp
composer require --no-interaction symfonycasts/tailwind-bundle
mkdir -p config/packages
cat > config/packages/symfonycasts_tailwind.yaml <<TAILWIND_CONFIG
symfonycasts_tailwind:
    binary_version: 'v4.3.3'
TAILWIND_CONFIG
php bin/console cache:clear
php bin/console tailwind:build

neutralize-app-env
