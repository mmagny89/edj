#!/bin/sh
# renseigner-secrets.sh — remplit les valeurs vides d'un fichier de secrets.
#
#   sh outils/renseigner-secrets.sh prod      # ou staging
#
# Les variables obligatoires sont declarees ${VAR:?} dans les overrides
# (conventions, section 5) : tant que l'une d'elles est vide, "docker compose
# build" lui-meme refuse de demarrer. Elles doivent donc etre produites AVANT le
# premier build — et par des outils qui ne doivent rien au projet, sans quoi
# l'instruction serait circulaire (section 22b).
#
# Ce script ne connait pas les variables d'un stack donne : il lit celles que le
# modele laisse vides et deduit de leur nom comment les produire.
set -eu

ENVIRONNEMENT=${1:-}

case "$ENVIRONNEMENT" in
	staging | prod) ;;
	*)
		echo "Usage : sh $0 staging|prod" >&2
		exit 1
		;;
esac

FICHIER=".env.${ENVIRONNEMENT}.local"
MODELE=".env.${ENVIRONNEMENT}.local.dist"

if [ ! -f "$FICHIER" ]; then
	if [ -f "$MODELE" ]; then
		cp "$MODELE" "$FICHIER"
		echo "$FICHIER cree depuis $MODELE."
	else
		echo "Ni $FICHIER ni $MODELE dans le repertoire courant." >&2
		echo "Lancer ce script depuis la racine du projet." >&2
		exit 1
	fi
fi

# --- Production d'une valeur, deduite du nom de la variable ------------------
# Le nom porte l'intention : un *_PASSWORD_HASH se hache, un *_SECRET se tire au
# hasard, un SERVER_NAME se demande. Tout le reste est demande en clair.
produire() {
	nom=$1

	case "$nom" in
		*PASSWORD_HASH)
			printf '  %s — mot de passe a hacher : ' "$nom" >&2
			stty -echo 2>/dev/null || true
			read -r motdepasse
			stty echo 2>/dev/null || true
			echo >&2
			[ -n "$motdepasse" ] || { echo "  Vide, abandon." >&2; exit 1; }
			MDP="$motdepasse" docker run --rm -e MDP php:8.5-cli \
				php -r 'echo password_hash(getenv("MDP"), PASSWORD_BCRYPT, ["cost" => 13]);'
			motdepasse=
			;;
		*SECRET | *HASH_SALT)
			# 32 octets pour un sel Drupal, 16 suffisent a un APP_SECRET Symfony ;
			# prendre le plus large ne coute rien.
			openssl rand -hex 32
			;;
		*PASSWORD)
			printf '  %s (saisie masquee) : ' "$nom" >&2
			stty -echo 2>/dev/null || true
			read -r valeur
			stty echo 2>/dev/null || true
			echo >&2
			printf '%s' "$valeur"
			;;
		*)
			printf '  %s : ' "$nom" >&2
			read -r valeur
			printf '%s' "$valeur"
			;;
	esac
}

# --- Reecriture ligne a ligne ------------------------------------------------
# Plutot qu'un sed : un hachage bcrypt contient des $ et des / qu'il faudrait
# echapper, et un echappement rate ne se verrait qu'au demarrage.
TEMPORAIRE=$(mktemp)
MANQUANTES=0

echo "Valeurs a renseigner dans $FICHIER :"

# Le fichier est parcouru par numero de ligne et non par une redirection : une
# boucle "while read < fichier" accapare l'entree standard, dont les invites de
# saisie ont besoin.
TOTAL=$(wc -l < "$FICHIER")
NUMERO=0

while [ "$NUMERO" -lt "$TOTAL" ]; do
	NUMERO=$((NUMERO + 1))
	ligne=$(sed -n "${NUMERO}p" "$FICHIER")

	case "$ligne" in
		[A-Z]*=)
			nom=${ligne%=}
			MANQUANTES=$((MANQUANTES + 1))
			valeur=$(produire "$nom")
			printf '%s=%s\n' "$nom" "$valeur" >> "$TEMPORAIRE"
			;;
		[A-Z]*="''")
			nom=${ligne%%=*}
			MANQUANTES=$((MANQUANTES + 1))
			valeur=$(produire "$nom")
			printf "%s='%s'\n" "$nom" "$valeur" >> "$TEMPORAIRE"
			;;
		*)
			printf '%s\n' "$ligne" >> "$TEMPORAIRE"
			;;
	esac
done

mv "$TEMPORAIRE" "$FICHIER"
chmod 600 "$FICHIER"

if [ "$MANQUANTES" = 0 ]; then
	echo "  (aucune — le fichier etait deja complet)"
fi

# --- Controle final ----------------------------------------------------------
echo
echo "Verification de la configuration Docker :"
if docker compose -f compose.yml -f "compose.${ENVIRONNEMENT}.yml" --env-file "$FICHIER" config >/dev/null 2>&1; then
	echo "  OK — plus aucune variable manquante."
	echo
	echo "Etape suivante :"
	echo "  docker compose -f compose.yml -f compose.${ENVIRONNEMENT}.yml --env-file $FICHIER up -d --build --wait"
else
	echo "  Il reste un probleme :"
	docker compose -f compose.yml -f "compose.${ENVIRONNEMENT}.yml" --env-file "$FICHIER" config 2>&1 >/dev/null | sed 's/^/    /'
	exit 1
fi
