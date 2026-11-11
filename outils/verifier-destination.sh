#!/bin/sh
# verifier-destination.sh — controle la destination des sauvegardes AVANT la crontab.
#
#   BACKUP_REMOTE=sauvegarde@hote:/srv/sauvegardes/projet/ sh outils/verifier-destination.sh
#
# Depose un petit fichier d'essai (sans donnee personnelle) sur la destination, le
# retrouve, puis le supprime. Echoue avec un message clair si la cle SSH n'est pas
# acceptee, si le dossier n'existe pas ou n'est pas inscriptible. A lancer avec le
# MEME utilisateur et la MEME cle que la crontab : c'est ce que la sauvegarde
# nocturne utilisera, et ce qu'on ne peut pas verifier a 2 h du matin.
set -eu

[ -n "${BACKUP_REMOTE:-}" ] || { echo "BACKUP_REMOTE manquant (ex. sauvegarde@hote:/srv/sauvegardes/projet/)." >&2; exit 1; }
command -v rsync > /dev/null || { echo "rsync absent sur ce serveur." >&2; exit 1; }

ESSAI=$(mktemp)
trap 'rm -f "$ESSAI"' EXIT
NOM="essai-sauvegarde-$(date -u +%Y%m%d-%H%M%S).txt"
echo "essai de destination" > "$ESSAI"

SSH="ssh -o BatchMode=yes -o ConnectTimeout=10"
HOTE=${BACKUP_REMOTE%%:*}
CHEMIN=${BACKUP_REMOTE#*:}

if ! rsync -e "$SSH" --chmod=F600 "$ESSAI" "${BACKUP_REMOTE%/}/$NOM"; then
	echo "Depot impossible sur $BACKUP_REMOTE : cle SSH non acceptee (BatchMode), dossier absent ou non inscriptible." >&2
	exit 1
fi
# shellcheck disable=SC2086
if $SSH "$HOTE" "test -f '${CHEMIN%/}/$NOM' && rm -f '${CHEMIN%/}/$NOM'"; then
	echo "Destination OK : depot, lecture et suppression reussis sur $BACKUP_REMOTE."
else
	echo "Le fichier a ete depose mais n'a pas pu etre relu ou supprime : verifier les droits du dossier." >&2
	exit 1
fi
