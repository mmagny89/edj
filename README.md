# Envie de Jouer — site de l'association

[![Qualite](https://github.com/mmagny89/edj/actions/workflows/qualite.yml/badge.svg)](https://github.com/mmagny89/edj/actions/workflows/qualite.yml)
[![Licence AGPL v3](https://img.shields.io/badge/licence-AGPL--3.0-blue.svg)](LICENSE)

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
├── .github/workflows/    ← qualite.yml (CI + deploiement), publication.yml (releases)
├── outils/               ← outillage d'exploitation, joue sur le serveur
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
docker compose exec php composer install
cd app && npm ci && npm run watch
```

> En developpement, utiliser `npm run dev` ou `npm run watch`, jamais
> `npm run build`. Ce dernier compile en mode production, donc avec des noms
> de fichiers horodates (`site.2f395926.css`) : WebpackEncoreBundle met
> `entrypoints.json` en cache dans `var/cache` et ne le relit pas quand seul
> le front est reconstruit — la page continue de demander l'ancien nom, qui
> vient d'etre supprime, et le site s'affiche sans aucun style. Si ca arrive :
> `docker compose exec php php bin/console cache:clear`.

> `npm` se lance depuis l'hote, pas depuis le conteneur : `app/node_modules`
> est un bind mount, et Tailwind 4 y installe un binaire natif (lightningcss)
> propre a la plateforme. Installer depuis le conteneur Linux casserait le
> `npm` de la machine, et inversement. En production la question ne se pose
> pas : l'image compile son front elle-meme.

Puis https://localhost (certificat auto-signe a accepter).

## Commandes du quotidien

```bash
docker compose exec php php bin/console cache:clear
docker compose exec php php bin/console make:migration
docker compose exec php php bin/console doctrine:migrations:migrate
docker compose logs -f php
docker compose down
```

Le courrier sortant du developpement est capture par Mailpit, consultable sur
http://127.0.0.1:8025 — rien ne part reellement.

## Deploiement et versions

Un push sur `main` suffit : les portes qualite passent, puis le meme workflow
construit l'image, la pousse sur GHCR et le serveur la deploie derriere
Traefik. Prealables, secrets a renseigner, rollback et operations manuelles :
**[README.docker.md](README.docker.md)**.

Une etiquette `v*` publie une release GitHub dont le corps est la section
correspondante de [CHANGELOG.md](CHANGELOG.md) — procedure complete dans
[README.docker.md](README.docker.md), section « Publier une version ».

## Mises a jour

Les versions d'images (PHP, FrankenPHP, PostgreSQL, Node) sont declarees dans
le `.env` racine, qui en est la source unique — la CI les y lit. Modifier la
valeur, ouvrir une PR, laisser le job `image` verifier que ca construit.

> Montee de version majeure de PostgreSQL : `pg_dump` avant, suppression du
> volume, restauration ensuite. Le chemin de montage du volume depend de la
> version majeure (voir le commentaire dans `compose.yml`).

Dependances applicatives :

```bash
docker compose exec php composer outdated
docker compose exec php npm outdated
```

## Contribuer, signaler

- [CONTRIBUTING.md](CONTRIBUTING.md) — branches, commits, ce que la CI verifie.
- [SECURITY.md](SECURITY.md) — signaler une faille, en prive, jamais par issue.
- [CHANGELOG.md](CHANGELOG.md) — ce que chaque version change.

## Licence

[AGPL-3.0-or-later](LICENSE) — Copyright (C) 2026 Association Envie de Jouer et
Mylene Magny. Toute reutilisation du code pour un service en ligne doit en
publier les sources modifiees.
