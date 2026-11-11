#!/bin/sh
# sauvegarder-serveur.sh — sauvegarde, pour TOUS les projets d'un serveur, la base de
# donnees (PostgreSQL ou MariaDB) et une copie du dossier du projet. A lancer SUR LE
# SERVEUR, par root, une fois par jour (cron).
#
#   BACKUP_PASSPHRASE_FILE=/root/.sauvegarde-phrase sh sauvegarder-serveur.sh
#
# Un projet = un dossier <RACINE>/<nom>-prod. Pour chacun, deux archives chiffrees
# en AES-256, rangees dans <DEST>/<projet>/ :
#
#   <projet>-<date>.sql.gz.enc            la base. Le moteur se lit dans le .env du
#                                         projet : POSTGRES_VERSION (pg_dump, conteneur
#                                         <projet>-database) ou MARIADB_VERSION
#                                         (mariadb-dump). Ni l'un ni l'autre (SQLite) :
#                                         pas de dump, signale au journal, la copie du
#                                         projet se fait quand meme.
#   <projet>-fichiers-<date>.tar.gz.enc   le dossier du projet, SECRETS COMPRIS
#                                         (.env.prod.local) : c'est pourquoi elle est
#                                         chiffree. Sans .git, node_modules, vendor, var/cache,
#                                         var/log, .claude ni sauvegardes/ : tout cela se
#                                         reconstruit (git, composer, npm).
#
# Rien ne touche le disque en clair : chaque flux passe par gzip puis openssl. Chaque
# archive est RELUE avant d'etre gardee — un dump coupe ou une phrase erronee ne
# doivent pas laisser une sauvegarde qui rassure sans proteger.
#
# Variables :
#   BACKUP_PASSPHRASE_FILE  OBLIGATOIRE. Fichier 0400 portant la phrase. La garder AUSSI
#                           dans un gestionnaire de mots de passe : sans elle, aucune
#                           sauvegarde ne se relit. Ne jamais la ranger a cote des archives.
#   RACINE      dossier des projets             (defaut : /docker)
#   DEST        dossier des sauvegardes         (defaut : /srv/sauvegardes)
#   RETENTION   copies gardees PAR TYPE et par projet (defaut : 7, soit une semaine a
#               raison d'une execution par jour). On compte des copies, pas des jours :
#               si les sauvegardes s'arretent, rien n'est efface.
#   EXCLURE     projets a ne pas traiter du tout, separes par des espaces. Un projet exclu
#               est NOMME au journal ; un projet qui disparait sans etre exclu fait
#               echouer le script.
#   BACKUP_REMOTE  destination rsync (ex. utilisateur@hote:/chemin/) : copie hors du
#                  serveur. Sans elle le script avertit, car une sauvegarde qui reste
#                  sur le serveur ne protege pas de sa perte.
#
# Ne couvre PAS : les volumes autres que la base (certificats Caddy, reconstructibles ;
# fichiers deposes par les utilisateurs, s'il en existe — a ajouter projet par projet).
#
# Code de sortie non nul si UN SEUL element echoue : le cron le dit, il ne se tait pas.
set -eu

RACINE=${RACINE:-/docker}
DEST=${DEST:-/srv/sauvegardes}
RETENTION=${RETENTION:-7}
PHRASE=${BACKUP_PASSPHRASE_FILE:-}
EXCLURE=${EXCLURE:-}

journal() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }

if [ -z "$PHRASE" ] || [ ! -s "$PHRASE" ]; then
	journal "ECHEC : BACKUP_PASSPHRASE_FILE absent ou vide. Rien n'est sauvegarde en clair."
	exit 1
fi

umask 077
mkdir -p "$DEST"
chmod 700 "$DEST"
ECHECS=0

echec() {
	journal "ECHEC $*"
	ECHECS=$((ECHECS + 1))
}

chiffrer_vers() { openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -salt -pass "file:$PHRASE" -out "$1"; }
dechiffrer() { openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -pass "file:$PHRASE" -in "$1"; }

# ---------------------------------------------------------------------- flux
# Les identifiants se lisent DANS le conteneur : ni ligne de commande, ni historique.
flux_base() {
	if [ "$MOTEUR" = postgres ]; then
		docker exec "$CONTENEUR" sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists --no-owner' | gzip
	else
		docker exec "$CONTENEUR" sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb-dump --single-transaction --routines --events --user "$MARIADB_USER" "$MARIADB_DATABASE"' | gzip
	fi
}

flux_fichiers() {
	tar -C "$RACINE" -czf - \
		--exclude=.git --exclude=node_modules \
		--exclude="$PROJET/vendor" --exclude="$PROJET/app/vendor" \
		--exclude="$PROJET/app/var/cache" --exclude="$PROJET/app/var/log" \
		--exclude="$PROJET/sauvegardes" --exclude="$PROJET/.claude" \
		"$PROJET"
}

# ------------------------------------------------------------------ relectures
# pg_dump 17.6+ ajoute des lignes apres la phrase de fin (\unrestrict) : on regarde les dix
# dernieres. mariadb-dump termine un export complet par « Dump completed ».
relire_base() { dechiffrer "$1" | gzip -dc | tail -n 10 | grep -q "$MARQUEUR"; }
# Un flux tronque ou une mauvaise phrase font echouer tar ; la liste n'est pas conservee.
relire_fichiers() { dechiffrer "$1" | tar -tzf - > /dev/null; }

# produire <fichier> <fonction de flux> <fonction de relecture> <libelle>
# Ne retourne jamais non nul (set -e) : un echec est compte, le projet suivant continue.
# Le statut d'un pipeline etant celui de sa derniere commande, un dump qui echoue en
# amont n'est vu que par la relecture — c'est ce qu'elle garantit.
produire() {
	fichier=$1
	partiel="$fichier.partiel"
	if ! "$2" | chiffrer_vers "$partiel"; then
		rm -f "$partiel"
		echec "$PROJET : $4, production ou chiffrement."
		return 0
	fi
	if ! "$3" "$partiel"; then
		rm -f "$partiel"
		echec "$PROJET : $4 illisible ou incomplete, rien n'est garde."
		return 0
	fi
	mv "$partiel" "$fichier"
	journal "OK $PROJET : $fichier ($(wc -c <"$fichier" | tr -d ' ') octets)"

	if [ -n "${BACKUP_REMOTE:-}" ]; then
		if rsync -e "ssh -o BatchMode=yes" --chmod=F600 "$fichier" "$BACKUP_REMOTE"; then
			journal "OK $PROJET : copiee vers $BACKUP_REMOTE"
		else
			echec "$PROJET : copie vers $BACKUP_REMOTE."
		fi
	fi
}

# purger <extension> : garde les RETENTION plus recentes de ce type.
purger() {
	# shellcheck disable=SC2012
	ls -1t "$cible"/*"$1" 2>/dev/null | tail -n +$((RETENTION + 1)) | while read -r ancienne; do
		rm -f "$ancienne"
		journal "Supprime (au-dela de $RETENTION) : $ancienne"
	done
}

# ------------------------------------------------------------------- projets
for dossier in "$RACINE"/*-prod; do
	[ -d "$dossier" ] || continue
	PROJET=$(basename "$dossier")
	CONTENEUR="${PROJET}-database"

	case " $EXCLURE " in
		*" $PROJET "*)
			journal "Ignore $PROJET : exclu par EXCLURE."
			continue
			;;
	esac

	cible="$DEST/$PROJET"
	mkdir -p "$cible"
	chmod 700 "$cible"
	date_archive=$(date -u +%Y%m%d-%H%M%S)

	MOTEUR=""
	MARQUEUR=""
	if grep -q '^POSTGRES_VERSION=' "$dossier/.env" 2>/dev/null; then
		MOTEUR=postgres
		MARQUEUR='PostgreSQL database dump complete'
	elif grep -q '^MARIADB_VERSION=' "$dossier/.env" 2>/dev/null; then
		MOTEUR=mariadb
		MARQUEUR='Dump completed'
	fi

	if [ -z "$MOTEUR" ]; then
		journal "Note $PROJET : ni POSTGRES_VERSION ni MARIADB_VERSION dans .env (SQLite ?) : pas de dump, copie du projet seulement."
	elif [ "$(docker inspect -f '{{.State.Running}}' "$CONTENEUR" 2>/dev/null || true)" != true ]; then
		echec "$PROJET : conteneur $CONTENEUR absent ou arrete."
	else
		produire "$cible/$PROJET-$date_archive.sql.gz.enc" flux_base relire_base "base $MOTEUR"
	fi

	produire "$cible/$PROJET-fichiers-$date_archive.tar.gz.enc" flux_fichiers relire_fichiers "copie du projet"

	purger .sql.gz.enc
	purger .tar.gz.enc
done

[ -n "${BACKUP_REMOTE:-}" ] || journal "AVERTISSEMENT : BACKUP_REMOTE non defini, les sauvegardes restent sur ce serveur."

if [ "$ECHECS" -gt 0 ]; then
	journal "$ECHECS echec(s)."
	exit 1
fi
journal "Toutes les sauvegardes sont faites."
