#!/bin/sh
# verifier-site — contrôle de recette d'un site en ligne, depuis N'IMPORTE OÙ.
#
#   sh outils/verifier-site.sh exemple.fr                  # domaine provisoire : site non indexable
#   sh outils/verifier-site.sh exemple.fr --indexable      # domaine définitif : site ouvert aux moteurs
#   sh outils/verifier-site.sh localhost --insecure        # certificat auto-signé (développement)
#
# Derrière Traefik, un conteneur « healthy » ne prouve rien : seule la réponse vue de
# l'extérieur compte. Ce script rejoue ce que l'on contrôlerait à la main après une mise en
# production — HTTPS, redirection, en-têtes de sécurité, indexation, pages privées, cookies,
# certificat — et sort avec un code non nul si un contrôle échoue.
#
# Généralisé à partir du script d'un projet : les pages et les hôtes qui n'appartiennent qu'à
# un site se déclarent dans outils/verifier-site.conf (facultatif), jamais dans ce fichier :
#
#   PAGES_PUBLIQUES="/ /mentions-legales /politique-de-confidentialite"   # doivent répondre 200
#   PAGES_PRIVEES="/admin /admin/utilisateurs"                            # inaccessibles sans connexion
#   PAGE_CONNEXION="/login"                                               # vide : contrôle ignoré
#   HOTES_EXTERNES_AUTORISES="exemple-partenaire.fr"                      # ressources tierces tolérées
#
# En développement (APP_DEBUG actif), la politique de contenu et HSTS ne sont pas posés : leur
# échec y est normal, et c'est ce qui le distingue d'un serveur de production.
set -u

DOMAINE=${1:-}
[ -n "$DOMAINE" ] || { echo "Usage : $0 domaine [--indexable] [--insecure]" >&2; exit 2; }
shift

INDEXABLE=0
CURL_TLS=''
for option in "$@"; do
	case "$option" in
		--indexable) INDEXABLE=1 ;;
		--insecure)  CURL_TLS='-k' ;;
		*) echo "Option inconnue : $option" >&2; exit 2 ;;
	esac
done

# Réglages du projet (valeurs par défaut valables pour le stack de l'agence).
PAGES_PUBLIQUES="/ /mentions-legales"
PAGES_PRIVEES="/admin"
PAGE_CONNEXION="/login"
HOTES_EXTERNES_AUTORISES=""
CONF="$(dirname "$0")/verifier-site.conf"
# shellcheck disable=SC1090
[ -f "$CONF" ] && . "$CONF"
PRIVEE=${PAGES_PRIVEES%% *}
# Hôtes tolérés, sous-domaines compris (www.exemple.fr pour exemple.fr), points échappés.
AUTORISES="$DOMAINE schema.org $HOTES_EXTERNES_AUTORISES"
AUTORISES=$(printf '%s' "$AUTORISES" | tr -s ' ' '\n' | sed 's/\./\\./g' | paste -sd '|' -)

BASE="https://$DOMAINE"
ECHECS=0

ok() { printf '  ok   %s\n' "$1"; }
ko() { printf '  ECHEC %s\n' "$1"; ECHECS=$((ECHECS + 1)); }

# curl sans erreur de proxy ni de lecture de configuration.
http() { curl -sS $CURL_TLS --max-time 20 "$@"; }

en_tetes() { http -D - -o /dev/null "$1" 2>/dev/null | tr -d '\r'; }
corps()    { http "$1" 2>/dev/null; }
# Toujours trois chiffres : `|| echo 000` après un -w qui a déjà imprimé 000 donnait « 000000 ».
code()     { c=$(http -o /dev/null -w '%{http_code}' "$1" 2>/dev/null) || true; printf '%s' "${c:-000}" | tail -c 3; }

echo "== $BASE"

echo "-- HTTPS et redirection"
[ "$(code "$BASE/")" = 200 ] && ok "l'accueil repond 200 en HTTPS" || ko "l'accueil ne repond pas 200 en HTTPS"

HTTP_CODE=$(curl -s --max-time 20 -o /dev/null -w '%{http_code}' "http://$DOMAINE/" 2>/dev/null || echo 000)
case "$HTTP_CODE" in
	301|302|307|308) ok "http:// redirige vers HTTPS ($HTTP_CODE)" ;;
	*) ko "http:// ne redirige pas vers HTTPS (code $HTTP_CODE)" ;;
esac

echo "-- en-tetes de securite"
ENTETES=$(en_tetes "$BASE/")
echo "$ENTETES" | grep -qi '^strict-transport-security:'      && ok "HSTS present"                    || ko "HSTS absent"
echo "$ENTETES" | grep -qi '^x-content-type-options: *nosniff' && ok "X-Content-Type-Options: nosniff" || ko "X-Content-Type-Options absent"
echo "$ENTETES" | grep -qi '^x-frame-options: *deny'           && ok "X-Frame-Options: DENY"            || ko "X-Frame-Options absent"
if echo "$ENTETES" | grep -qi '^content-security-policy:'; then
	if echo "$ENTETES" | grep -i '^content-security-policy:' | grep -q "script-src[^;]*'unsafe-inline'"; then
		ko "la politique de contenu autorise les scripts en ligne (unsafe-inline)"
	else
		ok "politique de contenu presente, sans script en ligne"
	fi
else
	ko "politique de contenu absente"
fi

echo "-- pages publiques"
HTML=$(corps "$BASE/")
echo "$HTML" | grep -q "<link rel=\"canonical\" href=\"$BASE/\"" && ok "URL canonique = $BASE/" || ko "URL canonique absente ou differente de $BASE/"
echo "$HTML" | grep -q 'property="og:image"'                    && ok "balises Open Graph presentes"  || ko "balises Open Graph absentes"
echo "$HTML" | grep -q 'application/ld+json'                    && ok "donnees structurees presentes" || ko "donnees structurees absentes"

EXTERNES=$(echo "$HTML" | grep -oE '(src|href)="https?://[^"]+"' | grep -vE "https?://([A-Za-z0-9-]+\.)*($AUTORISES)([/:?#\"]|$)" || true)
[ -z "$EXTERNES" ] && ok "aucune ressource externe chargee par la page" || ko "ressources externes : $(echo "$EXTERNES" | head -3 | tr '\n' ' ')"

for chemin in $PAGES_PUBLIQUES; do
	[ "$(code "$BASE$chemin")" = 200 ] && ok "$chemin repond 200" || ko "$chemin ne repond pas 200"
done

[ "$(code "$BASE/cette-page-n-existe-pas")" = 404 ] && ok "une page inconnue repond 404" || ko "une page inconnue ne repond pas 404"

echo "-- referencement"
ROBOTS=$(corps "$BASE/robots.txt")
META=$(echo "$HTML" | grep -o '<meta name="robots" content="[^"]*"' | head -1)
if [ "$INDEXABLE" = 1 ]; then
	echo "$ROBOTS" | grep -q "^Sitemap: $BASE/sitemap.xml" && ok "robots.txt annonce le plan du site" || ko "robots.txt n'annonce pas le plan du site"
	echo "$ROBOTS" | grep -q "^Disallow: $PRIVEE"           && ok "robots.txt ferme $PRIVEE"             || ko "robots.txt ne ferme pas $PRIVEE"
	echo "$ROBOTS" | grep -qx 'Disallow: /'                && ko "robots.txt interdit tout le site, alors qu'il doit etre indexable" || ok "robots.txt n'interdit pas le site"
	echo "$META" | grep -q 'index, follow'                 && ok "pages en « index, follow »"           || ko "pages non indexables : $META"
else
	echo "$ROBOTS" | grep -qx 'Disallow: /'                && ok "robots.txt interdit tout (domaine non definitif)" || ko "robots.txt n'interdit pas tout, alors que le site ne doit pas etre indexe"
	echo "$META" | grep -q 'noindex'                       && ok "pages en « noindex »"                 || ko "pages indexables : $META"
fi
[ "$(code "$BASE/sitemap.xml")" = 200 ] && ok "sitemap.xml repond 200" || ko "sitemap.xml ne repond pas 200"

echo "-- pages privees"
for chemin in $PAGES_PRIVEES; do
	CODE_ANONYME=$(code "$BASE$chemin")
	case "$CODE_ANONYME" in
		30*|401|403) ok "$chemin inaccessible sans connexion ($CODE_ANONYME)" ;;
		*) ko "$chemin ACCESSIBLE sans connexion (code $CODE_ANONYME)" ;;
	esac
done
if [ -n "$PAGE_CONNEXION" ]; then
	PRIV=$(en_tetes "$BASE$PRIVEE")
	echo "$PRIV" | grep -i '^location:' | grep -q "$PAGE_CONNEXION" && ok "$PRIVEE renvoie un visiteur anonyme vers $PAGE_CONNEXION" || ko "$PRIVEE ne renvoie pas un visiteur anonyme vers $PAGE_CONNEXION"
	LOGIN=$(en_tetes "$BASE$PAGE_CONNEXION")
	echo "$LOGIN" | grep -qi '^cache-control:.*no-store' && ok "$PAGE_CONNEXION interdit le cache"     || ko "$PAGE_CONNEXION n'interdit pas le cache"
	echo "$LOGIN" | grep -qi '^x-robots-tag:.*noindex'   && ok "$PAGE_CONNEXION interdit l'indexation" || ko "$PAGE_CONNEXION n'interdit pas l'indexation"
	[ "$(code "$BASE$PAGE_CONNEXION")" = 200 ] && ok "$PAGE_CONNEXION repond 200" || ko "$PAGE_CONNEXION ne repond pas 200"
fi

echo "-- certificat"
if command -v openssl >/dev/null 2>&1 && [ -z "$CURL_TLS" ]; then
	FIN=$(echo | openssl s_client -servername "$DOMAINE" -connect "$DOMAINE:443" 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)
	if [ -n "$FIN" ]; then
		if echo | openssl s_client -servername "$DOMAINE" -connect "$DOMAINE:443" 2>/dev/null | openssl x509 -noout -checkend $((14 * 86400)) >/dev/null 2>&1; then
			ok "certificat valide au moins 14 jours (expire : $FIN)"
		else
			ko "certificat expire dans moins de 14 jours (ou invalide) : $FIN"
		fi
	else
		ko "certificat illisible"
	fi
else
	echo "  --   certificat non controle (openssl absent, ou --insecure)"
fi

echo
if [ "$ECHECS" -eq 0 ]; then
	echo "Tous les controles passent."
else
	echo "$ECHECS controle(s) en echec."
	exit 1
fi
