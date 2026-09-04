#!/bin/sh
# neutralize-app-env — aucune option. Contrat fixe (conventions, section 7) :
# commente dans app/.env toute variable egalement injectee par le stack
# Docker (compose.yml ou ENV du Dockerfile), en laissant une ligne de
# commentaire indiquant que la source de verite est compose.yml. Rejouable
# apres chaque installation de paquet susceptible de deposer une recette
# (ex. la recette Doctrine qui ecrit DATABASE_URL).
set -e

ENV_FILE=/app/.env

if [ ! -f "$ENV_FILE" ]; then
	exit 0
fi

# Variables dont la valeur reelle est toujours fournie par le conteneur
# (compose.yml ou ENV du Dockerfile) — jamais par app/.env.
INJECTED_VARS="DATABASE_URL APP_ENV MAILER_DSN TRUSTED_PROXIES"

for var in $INJECTED_VARS; do
	awk -v var="$var" '
		BEGIN { pattern = "^" var "=" }
		$0 ~ pattern {
			print "# " $0 "  # source of truth: compose.yml (neutralized by neutralize-app-env)"
			next
		}
		{ print }
	' "$ENV_FILE" > "${ENV_FILE}.neutralized"
	mv "${ENV_FILE}.neutralized" "$ENV_FILE"
done
