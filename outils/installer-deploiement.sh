#!/bin/sh
# installer-deploiement.sh — pose les acces necessaires au deploiement continu.
# A lancer SUR LE SERVEUR.
#
#   sh outils/installer-deploiement.sh serveur              # une fois par serveur
#   sh outils/installer-deploiement.sh projet prod|staging  # une fois par projet, depuis sa racine
#
# Deux directions, deux portees, et c'est voulu (conventions, section 22c) :
#
#   serveur → GitHub  : UNE cle globale, celle d'un compte machine. La
#                       globaliser supprime toute ceremonie par projet.
#   GitHub  → serveur : UNE cle PAR projet. La partager n'economiserait rien —
#                       les secrets se posent de toute facon depot par depot —
#                       et elargirait le rayon d'explosion d'une fuite.
set -eu

MODE=${1:-}

titre() {
	echo
	echo "=== $* ==="
}

# ---------------------------------------------------------------- volet serveur
installer_serveur() {
	CLE="$HOME/.ssh/github_machine"

	mkdir -p "$HOME/.ssh"
	chmod 700 "$HOME/.ssh"

	if [ -f "$CLE" ]; then
		echo "Cle deja presente : $CLE"
	else
		ssh-keygen -t ed25519 -C "compte-machine-$(hostname)" -f "$CLE" -N "" >/dev/null
		echo "Cle creee : $CLE"
	fi

	if grep -qE '^Host github\.com$' "$HOME/.ssh/config" 2>/dev/null; then
		echo "Entree 'Host github.com' deja presente dans ~/.ssh/config, inchangee."
	else
		cat >> "$HOME/.ssh/config" <<-FIN

		Host github.com
		    HostName github.com
		    User git
		    IdentityFile $CLE
		    IdentitiesOnly yes
		FIN
		chmod 600 "$HOME/.ssh/config"
		echo "Entree ajoutee a ~/.ssh/config."
	fi

	titre "A FAIRE DANS GITHUB"
	echo "Connectez-vous avec le compte machine — un compte GitHub dedie, invite"
	echo "en lecture seule sur les depots a deployer — puis :"
	echo
	echo "  Settings → SSH and GPG keys → New SSH key"
	echo
	echo "Collez cette cle publique :"
	echo
	cat "$CLE.pub"
	echo
	echo "Puis verifiez depuis ce serveur :"
	echo
	echo "  ssh -T git@github.com"
	echo
	echo "Reponse attendue : « Hi <compte-machine>! You've successfully"
	echo "authenticated, but GitHub does not provide shell access. »"
	echo
	echo "Un nouveau projet ne demandera plus qu'une chose : inviter ce compte"
	echo "en lecture sur son depot."
}

# ----------------------------------------------------------------- volet projet
installer_projet() {
	ENVIRONNEMENT=${1:-}

	case "$ENVIRONNEMENT" in
		prod | staging) ;;
		*)
			echo "Usage : sh $0 projet prod|staging" >&2
			exit 1
			;;
	esac

	PROJET_CHEMIN=$(pwd)
	PROJET=$(basename "$PROJET_CHEMIN" | tr '[:upper:]' '[:lower:]')
	DEPLOYEUR="$PROJET_CHEMIN/outils/deployer.sh"

	if [ ! -f "$DEPLOYEUR" ]; then
		echo "ECHEC : $DEPLOYEUR introuvable." >&2
		echo "Lancer ce script depuis la racine du projet, sur le serveur." >&2
		exit 1
	fi
	chmod +x "$DEPLOYEUR"

	CLE="$HOME/.ssh/${PROJET}_${ENVIRONNEMENT}_actions"
	mkdir -p "$HOME/.ssh"
	chmod 700 "$HOME/.ssh"

	if [ -f "$CLE" ]; then
		echo "Cle deja presente : $CLE"
	else
		ssh-keygen -t ed25519 -C "github-actions-${PROJET}-${ENVIRONNEMENT}" -f "$CLE" -N "" >/dev/null
		echo "Cle creee : $CLE"
	fi

	# La forced command fige la commande ET son argument : ce que GitHub envoie
	# n'influence rien. Les options coupent tout ce dont un deploiement n'a pas
	# besoin — une cle qui fuite ne donne pas un shell.
	LIGNE="command=\"$DEPLOYEUR $ENVIRONNEMENT\",no-agent-forwarding,no-port-forwarding,no-pty,no-user-rc,no-X11-forwarding $(cat "$CLE.pub")"

	touch "$HOME/.ssh/authorized_keys"
	chmod 600 "$HOME/.ssh/authorized_keys"

	# La ligne est remplacee et non simplement ajoutee : un clone deplace, ou
	# une premiere pose depuis le mauvais repertoire, laisse sinon une forced
	# command pointant vers un chemin inexistant — et le deploiement echoue sur
	# un « No such file or directory » que relancer ce script ne corrigeait pas.
	EMPREINTE_CLE=$(cut -d' ' -f2 < "$CLE.pub")

	if grep -qF "$EMPREINTE_CLE" "$HOME/.ssh/authorized_keys"; then
		ANCIENNE=$(grep -F "$EMPREINTE_CLE" "$HOME/.ssh/authorized_keys" | head -1)

		if [ "$ANCIENNE" = "$LIGNE" ]; then
			echo "Cle deja autorisee, ligne identique."
		else
			TEMPORAIRE=$(mktemp)
			grep -vF "$EMPREINTE_CLE" "$HOME/.ssh/authorized_keys" > "$TEMPORAIRE" || true
			printf '%s\n' "$LIGNE" >> "$TEMPORAIRE"
			cat "$TEMPORAIRE" > "$HOME/.ssh/authorized_keys"
			rm -f "$TEMPORAIRE"
			echo "Ligne mise a jour — elle pointait ailleurs :"
			echo "  avant : $(printf '%s' "$ANCIENNE" | sed -E 's/^command="([^"]*)".*/\1/')"
			echo "  apres : $DEPLOYEUR $ENVIRONNEMENT"
		fi
	else
		printf '%s\n' "$LIGNE" >> "$HOME/.ssh/authorized_keys"
		echo "Cle autorisee, restreinte a : $DEPLOYEUR $ENVIRONNEMENT"
	fi

	# Purge des lignes devenues caduques. Le nom de la cle derive du dossier :
	# renommer le clone en cree donc une nouvelle, et l'ancienne reste autorisee,
	# pointant vers un chemin disparu. Si GitHub detient encore cette ancienne
	# cle, le deploiement echoue sur « No such file or directory » pendant que ce
	# script annonce « ligne identique » — il parle d'une autre cle.
	CADUQUES=$(awk -F'"' '/^command="/ {
		split($2, morceaux, " ");
		if (system("[ -x \"" morceaux[1] "\" ]") != 0) print $2;
	}' "$HOME/.ssh/authorized_keys")

	if [ -n "$CADUQUES" ]; then
		TEMPORAIRE=$(mktemp)
		awk -F'"' '!/^command="/ { print; next }
			{
				split($2, morceaux, " ");
				if (system("[ -x \"" morceaux[1] "\" ]") == 0) print;
			}' "$HOME/.ssh/authorized_keys" > "$TEMPORAIRE"
		cat "$TEMPORAIRE" > "$HOME/.ssh/authorized_keys"
		rm -f "$TEMPORAIRE"

		echo
		echo "Lignes retirees, leur cible n'existe plus :"
		printf '%s\n' "$CADUQUES" | sed 's/^/  /'
		echo "Si GitHub detenait la cle correspondante, remplacez DEPLOIEMENT_CLE_PRIVEE"
		echo "par celle affichee plus bas."
	fi

	# Ce que la forced command lancera doit exister et etre executable : c'est
	# la seule verification qui distingue une pose reussie d'un exit 127 au
	# premier declenchement.
	if [ ! -x "$DEPLOYEUR" ]; then
		echo "ECHEC : $DEPLOYEUR n'est pas executable." >&2
		exit 1
	fi

	titre "EMPREINTE DU SERVEUR"
	echo "known_hosts est indexe par l'adresse EXACTE de connexion : une IP et un"
	echo "nom de domaine y sont deux entrees distinctes. Donnez ici l'adresse que"
	echo "le workflow utilisera, telle quelle."
	printf 'Adresse de connexion SSH : '
	read -r ADRESSE

	if [ -z "$ADRESSE" ]; then
		echo "ECHEC : adresse vide." >&2
		exit 1
	fi

	EMPREINTES=$(ssh-keyscan -t ed25519,rsa "$ADRESSE" 2>/dev/null || true)
	if [ -z "$EMPREINTES" ]; then
		echo "ECHEC : aucune reponse de $ADRESSE. Adresse ou port injoignable." >&2
		exit 1
	fi

	titre "A COLLER DANS GITHUB"
	echo "Depot → Settings → Secrets and variables → Actions → New repository secret"
	echo
	echo "DEPLOIEMENT_HOTE"
	echo "$ADRESSE"
	echo
	echo "DEPLOIEMENT_UTILISATEUR"
	echo "$(id -un)"
	echo
	echo "DEPLOIEMENT_KNOWN_HOSTS"
	echo "$EMPREINTES"
	echo
	echo "DEPLOIEMENT_CLE_PRIVEE"
	echo "  La cle privee n'est pas affichee ici : une cle qui passe par un"
	echo "  terminal finit dans son historique, et de la dans un copier-coller"
	echo "  malheureux — un ticket, une conversation, un canal d'equipe."
	echo
	echo "  L'afficher au moment de la coller, et seulement a ce moment :"
	echo
	echo "    cat $CLE"
	echo
	echo "  Puis effacer la trace : clear && history -c"
	echo
	echo "  Si elle a ete divulguee, la remplacer ne coute rien :"
	echo "    rm -f $CLE $CLE.pub && sh outils/installer-deploiement.sh projet $ENVIRONNEMENT"
	echo
	if [ "$(id -u)" = "0" ]; then
		echo
		echo "AVERTISSEMENT : ce deploiement tournera sous root."
		echo "  La forced command empeche d'obtenir un shell, mais une cle qui"
		echo "  fuite s'executerait avec les droits les plus eleves de la machine."
		echo "  Un utilisateur dedie, membre du groupe docker et proprietaire du"
		echo "  clone, limiterait la portee. A envisager hors periode sensible."
	fi

	titre "VERIFIER"
	echo "Depuis un autre poste, la cle ne doit rien pouvoir faire d'autre :"
	echo
	echo "  ssh -i <cle privee> $(id -un)@$ADRESSE whoami"
	echo
	echo "Attendu : le deploiement se lance malgre la commande demandee — c'est"
	echo "la forced command qui gagne. Elle echouera faute de secrets renseignes,"
	echo "et c'est le comportement voulu."
}

case "$MODE" in
	serveur) installer_serveur ;;
	projet)  shift; installer_projet "$@" ;;
	*)
		echo "Usage :" >&2
		echo "  sh $0 serveur              # une fois par serveur (lecture des depots)" >&2
		echo "  sh $0 projet prod|staging  # une fois par projet, depuis sa racine" >&2
		exit 1
		;;
esac
