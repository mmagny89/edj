# Envie de Jouer — site de l'association

Application web de l'association **Envie de Jouer** (Joigny). En production
sur https://edj.mmagny.fr.

| Composant | Version |
|---|---|
| PHP | 8.4 (via FrankenPHP, mode worker) |
| Symfony | 8.0 |
| PostgreSQL | 18 |
| FrankenPHP / Caddy | 1.12 |
| Node.js | 24 (Webpack Encore) |
| Tailwind CSS | 4 |

## Structure

```
/
├── .github/workflows/    ← CI (qualite) et deploiement (GHCR + VPS)
├── docker/php/           ← Dockerfile multi-stage, Caddyfile, scripts
├── compose.yml           ← socle, sans port ni bind mount
├── compose.dev.yml       ← developpement
├── compose.staging.yml   ← recette
├── compose.prod.yml      ← production, derriere Traefik
├── .env                  ← variables Docker Compose (committe, sans secret)
└── app/                  ← application Symfony
```

La racine ne contient que de l'infrastructure : tout le code applicatif vit
dans `app/`, qui est aussi ce que voit le conteneur sous `/app`.

## Demarrer en local

```bash
cp app/.env.local.example app/.env.local   # puis renseigner APP_SECRET
docker compose up -d --wait
docker compose exec edj-php composer install
cd app && npm ci && npm run watch
```

> En developpement, utiliser `npm run dev` ou `npm run watch`, jamais
> `npm run build`. Ce dernier compile en mode production, donc avec des noms
> de fichiers horodates (`site.2f395926.css`) : WebpackEncoreBundle met
> `entrypoints.json` en cache dans `var/cache` et ne le relit pas quand seul
> le front est reconstruit — la page continue de demander l'ancien nom, qui
> vient d'etre supprime, et le site s'affiche sans aucun style. Si ca arrive :
> `docker compose exec edj-php php bin/console cache:clear`.

> `npm` se lance depuis l'hote, pas depuis le conteneur : `app/node_modules`
> est un bind mount, et Tailwind 4 y installe un binaire natif (lightningcss)
> propre a la plateforme. Installer depuis le conteneur Linux casserait le
> `npm` de la machine, et inversement. En production la question ne se pose
> pas : l'image compile son front elle-meme.

Puis https://localhost (certificat auto-signe a accepter).

## Commandes du quotidien

```bash
docker compose exec edj-php php bin/console cache:clear
docker compose exec edj-php php bin/console make:migration
docker compose exec edj-php php bin/console doctrine:migrations:migrate
docker compose logs -f edj-php
docker compose down
```

Le courrier sortant du developpement est capture par Mailpit, consultable sur
http://127.0.0.1:8025 — rien ne part reellement.

## Deploiement

Un push sur `main` suffit : la CI construit l'image, la pousse sur GHCR et le
VPS la deploie derriere Traefik. Prealables, secrets a renseigner, rollback et
operations manuelles : **[README.docker.md](README.docker.md)**.

## Mises a jour

Les versions d'images (PHP, FrankenPHP, PostgreSQL, Node) sont declarees dans
le `.env` racine, qui en est la source unique — la CI les y lit. Modifier la
valeur, ouvrir une PR, laisser le job `image` verifier que ca construit.

> Montee de version majeure de PostgreSQL : `pg_dump` avant, suppression du
> volume, restauration ensuite. Le chemin de montage du volume depend de la
> version majeure (voir le commentaire dans `compose.yml`).

Dependances applicatives :

```bash
docker compose exec edj-php composer outdated
docker compose exec edj-php npm outdated
```
