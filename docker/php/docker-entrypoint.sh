#!/bin/sh
# docker-entrypoint — aucune option, jamais. Contrat fixe (conventions,
# section 7) :
#   1. attend la base de donnees ;
#   2. bascule sur Caddyfile.bootstrap tant que /app/public/index.php est
#      absent, sinon charge la configuration nominale ;
#   3. joue la mise a jour de schema Doctrine selon RUN_MIGRATIONS, si la
#      console et l'application sont effectivement presentes ;
#   4. execute la commande recue (CMD ou commande explicite).
set -e

wait_for_database() {
	php -r '
		$url = getenv("DATABASE_URL");
		if ($url === false || $url === "") {
			exit(0);
		}

		$parts = parse_url($url);
		$host = $parts["host"] ?? null;
		$port = $parts["port"] ?? 5432;
		if ($host === null) {
			exit(0);
		}

		$deadline = time() + 30;
		while (time() < $deadline) {
			$conn = @fsockopen($host, $port, $errno, $errstr, 2);
			if ($conn) {
				fclose($conn);
				exit(0);
			}
			sleep(1);
		}

		fwrite(STDERR, "docker-entrypoint: database not reachable at {$host}:{$port} after 30s\n");
		exit(1);
	'
}

wait_for_database

if [ -f /app/public/index.php ]; then
	cp /etc/caddy/Caddyfile.nominal /etc/caddy/Caddyfile
else
	cp /etc/caddy/Caddyfile.bootstrap /etc/caddy/Caddyfile
fi

if [ -f /app/public/index.php ] && [ -f /app/bin/console ] && [ "$RUN_MIGRATIONS" = "1" ]; then
	php /app/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

exec "$@"
