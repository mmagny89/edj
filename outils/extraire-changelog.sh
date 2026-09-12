#!/usr/bin/env sh
# extraire-changelog — isole la section d'une version dans CHANGELOG.md.
#
#   Usage : sh outils/extraire-changelog.sh 0.12.0
#           (le « v » d'une etiquette git est accepte et ignore)
#
# Ecrit la section sur la sortie standard, sans son titre : ce qui suit
# « ## [0.12.0] » jusqu'au « ## » suivant, references de liens en bas de
# fichier exclues. C'est exactement le corps attendu par une release GitHub.
#
# Pourquoi un script versionne plutot que trois lignes d'awk dans le workflow :
# il se joue en local avant d'etiqueter, donc une section mal formee se voit
# avant la publication et non apres. Meme raison que outils/deployer.sh.
set -eu

VERSION="${1:?usage : extraire-changelog.sh <version>}"
VERSION="${VERSION#v}"
CHANGELOG="${2:-CHANGELOG.md}"

[ -f "$CHANGELOG" ] || { echo "extraire-changelog: $CHANGELOG introuvable" >&2; exit 2; }

SECTION="$(awk -v version="$VERSION" '
	# Le titre cherche : ## [0.12.0] - 2026-09-12
	$0 ~ "^## \\[" version "\\]" { dedans = 1; next }
	dedans && /^## / { exit }
	dedans { print }
' "$CHANGELOG" | sed -e '/^\[.*\]: http/d')"

# Retire les lignes vides en tete et en queue.
SECTION="$(printf '%s\n' "$SECTION" | awk '
	{ l[NR] = $0 }
	END {
		debut = 1; while (debut <= NR && l[debut] ~ /^[[:space:]]*$/) debut++
		fin = NR;  while (fin >= debut && l[fin] ~ /^[[:space:]]*$/) fin--
		for (i = debut; i <= fin; i++) print l[i]
	}
')"

if [ -z "$SECTION" ]; then
	echo "extraire-changelog: aucune section « ## [$VERSION] » dans $CHANGELOG" >&2
	echo "extraire-changelog: sections presentes :" >&2
	grep -oE '^## \[[^]]+\]' "$CHANGELOG" >&2
	exit 1
fi

printf '%s\n' "$SECTION"
