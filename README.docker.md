# edj — socle Docker et deploiement

Symfony 8 sur FrankenPHP (Caddy integre, mode worker), PostgreSQL 18,
observabilite via Ember. Front compile par **Webpack Encore** (`app/assets/`
vers `app/public/build/`).

Structure et nommage conformes a `.claude/rules/stack-conventions.md`. Les
ecarts assumes sont listes en fin de document.

Serveur staging/prod : **partage**. Traefik est en frontal sur le reseau
externe `proxy`, termine le TLS et route par domaine. Le conteneur PHP ne
publie aucun port : il sert en clair sur son `:80` interne.

## Inventaire

| Fichier | Role |
|---|---|
| `.env` | Variables Docker Compose : versions, ports, UID/GID, `POSTGRES_*`. Committe, sans secret |
| `.env.staging.local.dist` / `.env.prod.local.dist` | Modeles de secrets, committes, valeurs sensibles vides |
| `.env.staging.local` / `.env.prod.local` | Secrets reels — **jamais** committes |
| `compose.yml` | Socle : services `edj-php`, `edj-database`, `edj-ember`. Aucun port, aucun bind mount |
| `compose.dev.yml` | Dev : ports publies, bind mount `./app`, Xdebug, Mailpit |
| `compose.staging.yml` / `compose.prod.yml` | Traefik, image GHCR, Ember sous profil `observability` |
| `docker/php/**` | Dockerfile multi-stage, Caddyfile nominal et de secours, entrypoint, healthcheck, scripts |
| `.github/workflows/ci.yml` | Verifications qualite sur chaque PR et push |
| `.github/workflows/deploy.yml` | Build, push GHCR et deploiement sur le VPS a chaque push sur `main` |
| `app/` | Application Symfony, avec son propre `.env` |

## Developpement

```sh
docker compose up -d --wait
```

Le `.env` racine porte `COMPOSE_FILE=compose.yml:compose.dev.yml` : un
`docker compose up` nu equivaut a `-f compose.yml -f compose.dev.yml`.

Premier demarrage — installer les dependances et compiler le front :

```sh
docker compose exec edj-php composer install
cd app && npm ci && npm run watch
```

`npm` se lance depuis l'hote, pas depuis le conteneur. `app/node_modules` est
un bind mount et Tailwind 4 y depose un binaire natif (lightningcss) propre a
la plateforme : une installation faite dans le conteneur Linux rend le `npm`
de l'hote inutilisable, et inversement — l'erreur est un
`Cannot find module '../lightningcss.<plateforme>.node'` au premier build.
Le stage `prod_builder` de l'image, lui, installe ses propres modules sans
bind mount : la production n'est pas concernee.

| Adresse | Quoi |
|---|---|
| https://localhost | Le site (Caddy fait son propre TLS, certificat auto-signe) |
| http://127.0.0.1:8025 | Mailpit — tout le courrier sortant du dev y atterrit, rien ne part reellement |
| http://localhost:9191 | Ember (observabilite) |

Xdebug est installe dans l'image de dev mais desactive. L'activer sans
rebuild : passer `XDEBUG_MODE=debug` dans le `.env`, puis
`docker compose up -d`.

## Les trois niveaux de `.env`

| Fichier | Lu par | Contient |
|---|---|---|
| `.env` (racine) | Docker Compose seul | versions, ports, `POSTGRES_*`, UID/GID |
| `.env.prod.local` | Docker Compose via `--env-file` | **toutes** les cles, valeurs reelles |
| `app/.env` | Symfony | valeurs par defaut applicatives |
| `app/.env.local` | Symfony | `APP_SECRET` de developpement (modele : `app/.env.local.example`) |

`--env-file` **remplace** le `.env` racine, il ne s'y ajoute pas : c'est
pourquoi les fichiers de secrets portent toutes les cles.

Une variable appartient a un seul niveau. `DATABASE_URL`, `APP_ENV`,
`MAILER_DSN` et `TRUSTED_PROXIES` sont injectees par `compose.yml` : elles
sont commentees dans `app/.env`, et ne doivent jamais y etre redefinies.

## Ports

| Variable | Valeur | Publie par |
|---|---|---|
| `HTTP_PORT` | 80 | dev |
| `HTTPS_PORT` | 443 | dev |
| `HTTP3_PORT` | 443/udp | dev |
| `POSTGRES_PORT` | 5432 | dev uniquement, sur `127.0.0.1` |
| `MAILPIT_UI_PORT` | 8025 | dev uniquement, sur `127.0.0.1` |
| `EMBER_PORT` | 9191 | dev et, sur `127.0.0.1`, staging/prod |

En staging et production, aucun port n'est publie : Traefik joint le
conteneur par le reseau `proxy`.

Pour faire tourner ce projet en parallele d'un autre en local, decaler
`HTTP_PORT`/`HTTPS_PORT`/`HTTP3_PORT` dans le `.env` — jamais en inspectant
l'hote (conventions, section 4).

## Deploiement

Tout push sur `main` declenche `.github/workflows/deploy.yml` :

1. le runner GitHub construit l'image (stage `prod`, front compile dedans) ;
2. il la pousse sur GHCR sous deux tags : le SHA du commit et `prod` ;
3. il pilote Docker Compose **a distance** via `DOCKER_HOST=ssh://` : les
   fichiers compose viennent du depot, les secrets des GitHub Secrets. Il n'y
   a donc ni copie du depot ni fichier de secrets a maintenir sur le VPS ;
4. la base est demarree, les migrations jouees, puis le stack redemarre avec
   `--wait` — l'etape echoue si le conteneur n'atteint pas l'etat `healthy`.

Le VPS ne construit jamais d'image : son CPU reste disponible pour servir
pendant le deploiement, et ce qui tourne est exactement ce qui a ete teste.

### Prealables sur le VPS — une seule fois

```sh
docker network create proxy
```

Traefik doit ecouter sur ce reseau, avoir un entrypoint `websecure` et un
resolveur ACME nomme `letsencrypt` (les noms utilises par les labels de
`compose.prod.yml`). Le DNS de `edj.mmagny.fr` doit pointer sur le VPS.

### Prealables cote GitHub

Secrets (Settings > Secrets and variables > Actions > Secrets) :

| Secret | Contenu |
|---|---|
| `SSH_HOST` | Adresse du VPS |
| `SSH_USER` | Utilisateur SSH, membre du groupe `docker` |
| `SSH_PRIVATE_KEY` | Cle privee de deploiement (sa cle publique dans `~/.ssh/authorized_keys` du VPS) |
| `SSH_KNOWN_HOSTS` | Sortie de `ssh-keyscan <adresse-du-vps>` — l'empreinte est verifiee, jamais ignoree |
| `APP_SECRET` | Secret applicatif Symfony : `php -r 'echo bin2hex(random_bytes(16));'` |
| `POSTGRES_PASSWORD` | Mot de passe PostgreSQL de production |
| `MAILER_DSN` | DSN SMTP reel, ou `null://null` tant qu'aucun envoi n'est en place |

Variables (meme page, onglet Variables) :

| Variable | Valeur |
|---|---|
| `APP_DOMAIN` | `edj.mmagny.fr` |
| `PROXY_NETWORK` | `proxy` |

`POSTGRES_PASSWORD` n'est lu qu'a la **premiere** initialisation du cluster :
le changer plus tard ne change pas le mot de passe de la base existante.

### Rollback

Le tag SHA est immuable. Pour revenir a un deploiement anterieur, relancer le
workflow `deploy` depuis l'onglet Actions en choisissant le commit voulu
(`workflow_dispatch`). Les migrations, elles, ne se rejouent pas a l'envers :
un retour en arriere qui traverse une migration destructrice demande une
restauration de sauvegarde.

### Operations manuelles sur le VPS

Depuis un poste ayant l'acces SSH et le depot :

```sh
export DOCKER_HOST=ssh://<user>@<vps>
docker compose -f compose.yml -f compose.prod.yml --env-file .env.prod.local ps
docker compose -f compose.yml -f compose.prod.yml --env-file .env.prod.local logs -f edj-php
```

Observabilite (Ember porte le profil `observability`, il ne demarre jamais
tout seul en production) :

```sh
docker compose -f compose.yml -f compose.prod.yml --env-file .env.prod.local --profile observability up -d edj-ember
```

Son port n'est publie que sur `127.0.0.1` : y acceder par un tunnel SSH.

## Ecarts assumes au manifeste

| Ecart | Pourquoi |
|---|---|
| `compose.staging.yml` / `compose.prod.yml` n'ont pas de section `build:` ; l'image vient de `${PHP_IMAGE}` | Le VPS ne construit rien, il tire une image GHCR construite et testee par la CI |
| Le Dockerfile ajoute un stage `node_upstream` et compile le front avec `npm ci && npm run build` dans `prod_builder` | Ce projet utilise Webpack Encore, la ou le gabarit `.claude/resources/symfony-docker` suppose AssetMapper + Tailwind CLI |
| `SERVER_NAME` vaut `:80` en staging/prod, le domaine public passe par `APP_DOMAIN` | Deux roles distincts : ce que Caddy sert dans le conteneur, et ce que Traefik route. Les confondre casse le healthcheck et provoque une redirection 308 |
| Service `edj-mailpit` present dans `compose.dev.yml` | Hors manifeste, mais strictement limite au developpement : le courrier ne doit jamais partir depuis un poste de developpeur |
