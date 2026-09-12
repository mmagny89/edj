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
| `compose.yml` | Socle : services `php`, `worker`, `database`, `ember`. Aucun port, aucun bind mount |
| `compose.dev.yml` | Dev : ports publies, bind mount `./app`, Xdebug, Mailpit |
| `compose.staging.yml` / `compose.prod.yml` | Traefik, stage d'image fige, Ember sous profil `observability` |
| `docker/php/**` | Dockerfile multi-stage, Caddyfile nominal et de secours, entrypoint, healthcheck, scripts |
| `.github/workflows/qualite.yml` | Portes qualite sur chaque PR et push, puis deploiement sur `main` |
| `.github/workflows/publication.yml` | Release GitHub sur etiquette `v*`, corps repris de `CHANGELOG.md` |
| `.github/dependabot.yml` | Surveillance des dependances Composer, npm, Docker et Actions |
| `outils/` | Outillage d'exploitation versionne, joue sur le serveur (voir plus bas) |
| `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`, `LICENSE` | Gouvernance du depot public |
| `app/` | Application Symfony, avec son propre `.env` |

Les conteneurs, le reseau et les volumes portent le suffixe d'environnement :
`edj-dev-php`, `edj-prod-database`, `edj-prod-database-data`… Il vient de la
variable `ENV` (`dev` dans le `.env` racine, `staging`/`prod` dans les fichiers
de secrets). Sans lui, recette et production deployees sur la meme machine
partageraient **le meme volume de base de donnees**. Les commandes, elles,
s'ecrivent toujours avec la cle de service nue : `docker compose exec php ...`.

## Developpement

```sh
docker compose up -d --wait
```

Le `.env` racine porte `COMPOSE_FILE=compose.yml:compose.dev.yml` : un
`docker compose up` nu equivaut a `-f compose.yml -f compose.dev.yml`.

Premier demarrage — installer les dependances et compiler le front :

```sh
docker compose exec php composer install
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
`MAILER_DSN` et `SYMFONY_TRUSTED_PROXIES` sont injectees par `compose.yml` : elles
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
| (metriques Caddy) | 2020 | **jamais publie** — joint par le reseau Docker, sur `edj-<env>-php:2020` |

En staging et production, aucun port n'est publie : Traefik joint le
conteneur par le reseau `proxy`.

Pour faire tourner ce projet en parallele d'un autre en local, decaler
`HTTP_PORT`/`HTTPS_PORT`/`HTTP3_PORT` dans le `.env` — jamais en inspectant
l'hote (conventions, section 4).

## Deploiement

Tout push sur `main` declenche le job `deploiement` de
`.github/workflows/qualite.yml`, apres les portes qualite et le build de
verification de l'image.

Ce job **n'envoie aucune commande**. Il ouvre une connexion SSH, et c'est la
*forced command* d'`authorized_keys` qui impose, cote serveur,
`outils/deployer.sh prod`. La cle confiee a GitHub ne peut donc rien lancer
d'autre, et les chemins comme les secrets ne quittent jamais la machine
(conventions, section 22).

`outils/deployer.sh`, versionne avec le projet, fait alors :

1. verifie que le clone est bien sur `main` — se deployer depuis la mauvaise
   branche passerait sinon inapercu ;
2. `git fetch` puis `merge --ff-only` : un deploiement ne fusionne rien et ne
   resout aucun conflit. Si l'avance rapide est impossible, quelqu'un a
   modifie le clone du serveur, et c'est a un humain de regarder ;
3. `up -d --build --wait` : l'image est construite **sur le serveur**, depuis
   le code qui vient d'etre tire ;
4. les migrations, explicitement — `RUN_MIGRATIONS` vaut 0 en production
   (conventions, section 8) ;
5. le menage des images orphelines, sans quoi le disque se remplit en silence ;
6. la seule verification qui compte : la reponse du site **depuis
   l'exterieur**. Derriere Traefik, un conteneur peut se declarer `healthy`
   alors que rien ne repond.

### Prealables sur le serveur — une seule fois

Le clone porte **le meme nom que le projet Compose**, suffixe d'environnement
compris (conventions, section 22b) : une interface qui deduit le nom du projet
du dossier afficherait sinon un projet fantome a cote du vrai, et la *forced
command*, qui porte un chemin absolu, pointerait dans le vide apres un
renommage.

```sh
docker network create proxy
git clone <depot> /srv/edj-prod && cd /srv/edj-prod
cp .env.prod.local.dist .env.prod.local
sh outils/renseigner-secrets.sh prod
sh outils/installer-deploiement.sh serveur       # une fois par machine
sh outils/installer-deploiement.sh projet prod   # une fois par projet
```

`renseigner-secrets.sh` se joue **avant** le premier build : les variables
obligatoires etant declarees `${VAR:?}`, `docker compose build` lui-meme
refuse de demarrer tant que l'une d'elles est vide. Il deduit du nom de chaque
variable comment la produire, et n'affiche jamais la cle privee au terminal.

`installer-deploiement.sh projet prod` cree la cle Actions, ecrit la ligne
`authorized_keys` avec sa *forced command*, releve l'empreinte a l'adresse
exacte que le workflow utilisera, et affiche les quatre secrets a coller dans
GitHub.

Traefik doit ecouter sur le reseau `proxy`, avoir un entrypoint `websecure` et
un resolveur ACME nomme `letsencrypt` — les noms utilises par les labels de
`compose.prod.yml`. Ils **se constatent sur l'hote**, ils ne se devinent pas :
`sh outils/diagnostic-traefik.sh`. Le DNS de `edj.mmagny.fr` doit pointer sur
le serveur.

Eprouver le deploiement manuel **avant** l'automatique : enchainer les deux
melerait deux sources d'echec, le stack et le dispositif SSH.

```sh
sh outils/deployer.sh prod
```

### Prealables cote GitHub

Secrets (Settings > Secrets and variables > Actions > Secrets), tous produits
par `installer-deploiement.sh projet prod` :

| Secret | Contenu |
|---|---|
| `DEPLOIEMENT_HOTE` | Adresse du serveur |
| `DEPLOIEMENT_UTILISATEUR` | Utilisateur de deploiement, membre du groupe `docker` |
| `DEPLOIEMENT_CLE_PRIVEE` | Cle privee dont la publique est dans `authorized_keys`, derriere la forced command |
| `DEPLOIEMENT_KNOWN_HOSTS` | Empreinte du serveur, a l'adresse exacte que le workflow utilise |

Le prefixe `DEPLOIEMENT_` est normatif (conventions, section 22) : il dit le
role, pas la machine, et reste identique d'un depot a l'autre. Le workflow
controle leur presence avant de s'en servir — GitHub remplace un secret absent
par une chaine vide, sans rien signaler.

Les valeurs applicatives (`APP_SECRET`, `POSTGRES_PASSWORD`, `APP_DOMAIN`,
`MAILER_DSN`) ne sont **pas** des secrets GitHub : elles vivent dans
`.env.prod.local`, sur le serveur, ou `renseigner-secrets.sh` les a ecrites.

`POSTGRES_PASSWORD` n'est lu qu'a la **premiere** initialisation du cluster :
le changer plus tard ne change pas le mot de passe de la base existante.

### Rollback

Le deploiement suivant la branche, un retour en arriere est un `git revert`
pousse sur `main` : le workflow repart, le serveur tire et reconstruit. Pour
un retour immediat sans attendre la CI, depuis le serveur :

```sh
cd /srv/edj-prod && git revert --no-edit <sha> && sh outils/deployer.sh prod
```

Les migrations, elles, ne se rejouent pas a l'envers : un retour en arriere
qui traverse une migration destructrice demande une restauration de
sauvegarde.

### Operations manuelles sur le serveur

En SSH sur le serveur, depuis le clone :

```sh
cd /srv/edj-prod
docker compose -f compose.yml -f compose.prod.yml --env-file .env.prod.local ps
docker compose -f compose.yml -f compose.prod.yml --env-file .env.prod.local logs -f php
```

Observabilite (Ember porte le profil `observability`, il ne demarre jamais
tout seul en production) :

```sh
docker compose -f compose.yml -f compose.prod.yml --env-file .env.prod.local --profile observability up -d ember
```

Son port n'est publie que sur `127.0.0.1` : y acceder par un tunnel SSH.

## Ecarts assumes au manifeste

| Ecart | Pourquoi |
|---|---|
| Le Dockerfile ajoute un stage `node_upstream` et compile le front avec `npm ci && npm run build` dans `prod_builder` | Ce projet utilise Webpack Encore, la ou le gabarit `.claude/resources/symfony-docker` suppose AssetMapper + Tailwind CLI |
| `SERVER_NAME` vaut `:80` en staging/prod, le domaine public passe par `APP_DOMAIN` | Deux roles distincts : ce que Caddy sert dans le conteneur, et ce que Traefik route. Les confondre casse le healthcheck et provoque une redirection 308 |
| Service `worker` (consommateur Messenger) dans `compose.yml` | Le manifeste ne prevoit pas de worker ; sans lui, aucun courriel de bulletin d'adhesion ne part et les taches planifiees ne tournent jamais |
| `MAILER_FROM` n'est pas injectee | L'expediteur est un parametre applicatif (`contactEmail`), pas une variable d'environnement. A basculer le jour ou un transport reel est en place |

## Outillage d'exploitation — `outils/`

Versionne avec le projet, donc present sur le serveur (`.claude/` ne quitte
jamais le poste de developpement).

| Script | Quand |
|---|---|
| `sh outils/renseigner-secrets.sh prod` | Remplir `.env.prod.local` : le script deduit du nom de chaque variable comment la produire. A jouer **avant** le premier build, les `${VAR:?}` bloquant `docker compose build` lui-meme |
| `sh outils/deployer.sh prod` | Le deploiement lui-meme (`git pull`, build, migrations, verification externe). Appele par la forced command SSH, ou a la main sur le serveur |
| `sh outils/installer-deploiement.sh serveur\|projet prod` | Poser les acces du deploiement continu : cles, `authorized_keys`, empreinte, secrets a coller dans GitHub |
| `sh outils/diagnostic-traefik.sh` | Relever sur l'hote le nom du reseau, l'entrypoint et le certresolver du Traefik en place — ils se constatent, ils ne se devinent pas |
| `sh outils/extraire-changelog.sh 1.0.0` | Verifier en local la section que la release publiera |

## Publier une version

1. Completer la section `## [Non publie]` de `CHANGELOG.md` au fil des
   changements, puis la renommer `## [1.1.0] - AAAA-MM-JJ` et ajouter les
   deux liens de comparaison en bas de fichier.
2. Verifier ce que la release affichera :
   `sh outils/extraire-changelog.sh 1.1.0`.
3. Etiqueter et pousser :

```sh
git tag -a v1.1.0 -m "v1.1.0"
git push origin v1.1.0
```

`publication.yml` cree alors la release GitHub, avec pour corps la section du
journal. Une etiquette posee sans section correspondante fait echouer le
workflow plutot que de publier une release vide.

## Migrer un stack genere avant le suffixe d'environnement

Les anciens volumes (`edj-caddy-data`, `edj-database-data`…) ne sont plus
trouves : le stack redemarrerait sur une base vide. Avant le premier `up`
apres cette bascule, recopier chaque volume sous son nouveau nom, puis
verifier la donnee :

```sh
docker volume create edj-prod-database-data
docker run --rm -v edj-database-data:/s:ro -v edj-prod-database-data:/c alpine cp -a /s/. /c/
```

En developpement, un `docker compose down --volumes` suivi d'un `up` et des
migrations est plus simple qu'une recopie.
