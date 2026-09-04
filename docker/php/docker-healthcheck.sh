#!/bin/sh
# docker-healthcheck — aucune option. Interroge l'endpoint /health servi par
# Caddy (configuration nominale ou de secours, les deux l'exposent). N'utilise
# que le binaire php, present dans toutes les images, y compris l'image de
# production rootless.
#
# L'adresse a sonder se deduit de SERVER_NAME, qui est deja ce qui dit a Caddy
# ce qu'il sert :
#
# - Un nom de domaine ("example.com") : Caddy fait son propre TLS, la sonde
#   passe en HTTPS sur 443. Elle cible 127.0.0.1 directement plutot que :80,
#   car Caddy redirige systematiquement le HTTP vers le HTTPS (y compris pour
#   "localhost", via son autorite de certification interne) et la verification
#   suivrait alors une redirection vers un hote different. Le SNI et le Host
#   sont forces sur SERVER_NAME pour que Caddy route vers le bon site et
#   presente le bon certificat ; la verification du certificat est desactivee,
#   l'appel etant en boucle locale et jamais expose au reseau.
# - Une adresse portee (":80") : le site est servi en clair, derriere un proxy
#   qui termine le TLS en amont — rien n'ecoute sur 443, et sonder le HTTPS
#   ferait echouer un conteneur parfaitement sain (defaut reel, paye au
#   premier deploiement derriere Traefik : "container niout-php is unhealthy",
#   sans le moindre indice dans les journaux, l'application repondant tres
#   bien sur son port).
set -e

php -r '
	$serverName = getenv("SERVER_NAME");
	if ($serverName === false || trim($serverName) === "") {
		$serverName = "localhost";
	}

	// SERVER_NAME peut porter plusieurs adresses separees par des espaces
	// (Caddy le permet) : la premiere suffit a joindre le site en local.
	$adresse = strtok(trim($serverName), " ");

	if (preg_match("#^(.*):(\d+)$#", $adresse, $m)) {
		$host = $m[1] !== "" ? $m[1] : "localhost";
		$port = (int) $m[2];
	} else {
		$host = $adresse;
		$port = 443;
	}

	$scheme = $port === 443 ? "https" : "http";

	$context = stream_context_create([
		"http" => [
			"timeout" => 3,
			"ignore_errors" => true,
			"header" => "Host: {$host}\r\n",
		],
		"ssl" => [
			"verify_peer" => false,
			"verify_peer_name" => false,
			"allow_self_signed" => true,
			"peer_name" => $host,
		],
	]);

	$body = @file_get_contents("{$scheme}://127.0.0.1:{$port}/health", false, $context);
	if ($body === false) {
		fwrite(STDERR, "docker-healthcheck: no response on :{$port}/health\n");
		exit(1);
	}

	$status = null;
	foreach ($http_response_header ?? [] as $header) {
		if (preg_match("#^HTTP/\S+\s+(\d+)#", $header, $m)) {
			$status = (int) $m[1];
		}
	}

	if ($status !== null && $status >= 500) {
		fwrite(STDERR, "docker-healthcheck: HTTP {$status} on :{$port}/health\n");
		exit(1);
	}

	exit(0);
'
