#!/bin/sh
# Diagnostic Traefik — a lancer sur le serveur cible.
#
# Traefik peut etre configure de quatre facons, et un projet donne n'en utilise
# souvent qu'une : arguments de ligne de commande, variables d'environnement,
# fichier statique monte, ou valeurs par defaut. Ce script regarde les quatre,
# plutot que de supposer laquelle est en place.
#
# Objectif : trouver les trois valeurs dont compose.prod.yml a besoin —
# le nom du reseau, le nom de l'entrypoint HTTPS, le nom du certresolver.
set -u

T=$(docker ps --format '{{.Names}}\t{{.Image}}' | grep -i traefik | head -1 | cut -f1)

if [ -z "$T" ]; then
	echo "AUCUN conteneur Traefik en cours d'execution."
	echo
	echo "Conteneurs actifs :"
	docker ps --format '  {{.Names}}\t{{.Image}}'
	exit 1
fi

echo "Conteneur Traefik : $T"
echo "Image             : $(docker inspect "$T" --format '{{.Config.Image}}')"
echo
echo "=== 1. RESEAUX (a reporter dans compose.prod.yml) ==="
docker inspect "$T" --format '{{range $k, $v := .NetworkSettings.Networks}}  {{$k}}{{"\n"}}{{end}}'

echo "=== 2. ARGUMENTS DE LIGNE DE COMMANDE ==="
docker inspect "$T" --format '{{range .Config.Cmd}}{{println .}}{{end}}' \
	| grep -iE "entrypoint|certificatesresolver|providers.docker" || echo "  (aucun — configuration ailleurs)"
echo

echo "=== 3. VARIABLES D'ENVIRONNEMENT ==="
docker inspect "$T" --format '{{range .Config.Env}}{{println .}}{{end}}' \
	| grep -iE "^TRAEFIK_" || echo "  (aucune)"
echo

echo "=== 4. FICHIER DE CONFIGURATION STATIQUE ==="
trouve=0
for f in /etc/traefik/traefik.yml /etc/traefik/traefik.yaml /etc/traefik/traefik.toml /traefik.yml /traefik.yaml /traefik.toml; do
	if docker exec "$T" sh -c "[ -f $f ]" 2>/dev/null; then
		echo "  --- $f ---"
		docker exec "$T" sh -c "cat $f" 2>/dev/null | sed 's/^/  /'
		trouve=1
		break
	fi
done
if [ "$trouve" = 0 ]; then
	echo "  (introuvable dans le conteneur — montages declares :)"
	docker inspect "$T" --format '{{range .Mounts}}  {{.Source}} -> {{.Destination}}{{"\n"}}{{end}}'
fi
echo

echo "=== 5. CE QUE FONT LES AUTRES PROJETS (le plus fiable) ==="
echo "Etiquettes de routage des conteneurs deja servis par Traefik :"
trouve=0
for c in $(docker ps --format '{{.Names}}'); do
	actif=$(docker inspect "$c" --format '{{index .Config.Labels "traefik.enable"}}' 2>/dev/null)
	[ "$actif" = "true" ] || continue
	trouve=1
	echo "  [$c]"
	docker inspect "$c" --format '{{range $k, $v := .Config.Labels}}{{$k}}={{$v}}{{"\n"}}{{end}}' 2>/dev/null \
		| grep -iE "entrypoints|certresolver|docker\.network" \
		| sed 's/^/    /'
done
[ "$trouve" = 0 ] && echo "  (aucun — ce projet serait le premier route par Traefik)"
exit 0
