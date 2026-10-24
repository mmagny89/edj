# Envie de Jouer — site de l'association

[![Qualité et déploiement](https://github.com/mmagny89/edj/actions/workflows/qualite.yml/badge.svg?branch=main)](https://github.com/mmagny89/edj/actions/workflows/qualite.yml)
[![Release](https://github.com/mmagny89/edj/actions/workflows/release.yml/badge.svg)](https://github.com/mmagny89/edj/actions/workflows/release.yml)
[![Version](https://img.shields.io/github/v/tag/mmagny89/edj?label=version&sort=semver)](CHANGELOG.md)
[![Licence AGPL-3.0](https://img.shields.io/badge/licence-AGPL--3.0-blue)](LICENSE)
[![Conventional Commits](https://img.shields.io/badge/Conventional_Commits-1.0.0-fe5196?logo=conventionalcommits&logoColor=white)](https://conventionalcommits.org/fr/v1.0.0/)
[![PHP 8.5](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)](.env)
[![Symfony 8.1](https://img.shields.io/badge/Symfony-8.1-000000?logo=symfony&logoColor=white)](app/composer.lock)
[![PostgreSQL 18](https://img.shields.io/badge/PostgreSQL-18-4169E1?logo=postgresql&logoColor=white)](.env)
[![FrankenPHP 1.13](https://img.shields.io/badge/FrankenPHP-1.13-5A4FCF)](.env)
[![Tailwind CSS 4](https://img.shields.io/badge/Tailwind_CSS-4-06B6D4?logo=tailwindcss&logoColor=white)](app/package.json)

Application web de l'association **Envie de Jouer** (Joigny) : le site public,
l'adhesion en ligne et l'administration de l'association. En production sur
https://edj.mmagny.fr.

> Les badges sont des images chargees par la page GitHub : aucune ressource
> externe n'entre dans l'application, qui n'appelle ni CDN ni service tiers.

## Ce que fait l'application

**Site public**

- Calendrier des soirees du mardi, des apres-midi du 1er samedi et des
  manifestations.
- Adhesion en ligne (individuelle, famille, consommateur), avec accuse de
  reception par courriel.
- Espace adherent sans compte ni mot de passe : un lien signe, valable trente
  minutes, ouvre la fiche, la carte d'adherent en PDF et le renouvellement.
- Installable comme une application sur l'ecran d'accueil, sans cache hors
  ligne : le site reste toujours a jour.
- Reglages d'accessibilite (contraste, taille du texte, liens soulignes, sans
  animation), polices et icones servies par le site, pages « Mentions legales »
  et « Donnees personnelles ».

**Administration** (`/admin`, comptes personnels, session coupee apres une
periode d'inactivite)

| Section | Role |
|---|---|
| Tableau de bord | Chiffres et graphiques d'une saison : adherents, renouvellements, age, territoire, reglements |
| Evenements | Calendrier de l'accueil ; generation d'une saison entiere (jours feries ecartes) |
| Adhesions | Validation et encaissement, fiche papier, correction, renouvellement, passage a l'annuel, anonymisation, export CSV |
| Courriels | Message aux adherents d'une saison, avec variables, apercu, essai, lien de desinscription et historique |
| Pointage | Presence et euro de chaque consommateur a une seance |
| Comptabilite | Journal de caisse d'une saison, reste a encaisser, export CSV |
| Emargement | Feuille d'emargement PDF pour une assemblee generale |
| Saisons, Import, Administrateurs | Reserves aux super-administrateurs : tampons des cartes, import d'un fichier Excel d'adherents, comptes (roles, blocage, mot de passe provisoire) |

Un administrateur qui a oublie son mot de passe en demande un nouveau depuis la
page de connexion : un lien a usage unique, valable trente minutes, part a
l'adresse de son compte.

## Stack

| Composant | Version |
|---|---|
| PHP | 8.5 (via FrankenPHP, mode worker) |
| Symfony | 8.1 |
| PostgreSQL | 18 |
| FrankenPHP / Caddy | 1.13 |
| Node.js | 26 (Webpack Encore) |
| Tailwind CSS | 4 |

Les versions d'images sont declarees dans le `.env` racine, qui en est la
source unique. Les badges ci-dessus les refletent : toute montee de version met
a jour le badge dans le meme commit.

## Structure

```
/
├── .github/workflows/    ← qualite.yml (CI + deploiement), release.yml (releases), php-correctif.yml
├── docs/                 ← mise en production, securite des donnees
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

Deux conteneurs applicatifs partagent la meme image : `php` (le site) et
`worker` (consommateur Messenger). Les courriels passent par la file asynchrone
et les taches recurrentes par le Scheduler : **sans le worker, rien ne part et
aucune tache ne tourne**, sans qu'aucune erreur ne le signale.

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

Le courrier sortant du developpement est capture par Mailpit, consultable sur
http://127.0.0.1:8025 — rien ne part reellement. En production, un transport de
courriel reel (`MAILER_DSN`, `MAILER_FROM` dans `.env.prod.local`) est
indispensable : avec `null://null`, les messages sont jetes sans erreur, y
compris les liens de mot de passe oublie.

> Le rechargement a chaud de PHP ne surveille que les fichiers de code
> (`compose.dev.yml`). Si le site cesse de repondre alors que le conteneur est
> `healthy` — seule `/health`, servie par Caddy, repond —, un redemarrage le
> remet en route : `docker compose restart php`.

## Commandes du quotidien

```bash
docker compose exec php php bin/console cache:clear
docker compose exec php php bin/console make:migration
docker compose exec php php bin/console doctrine:migrations:migrate
docker compose logs -f php
docker compose down
```

## Tests et controles

Pendant le developpement, seuls les tests concernes ; la suite complete et les
controles une seule fois, avant de committer ou de pousser. Ne jamais lancer
deux suites en meme temps : elles partagent la base de test.

```bash
docker compose exec php vendor/bin/phpunit tests/Controller/MailingTest.php   # un fichier
docker compose exec php vendor/bin/phpunit --filter testNomDuTest              # un filtre
docker compose exec php vendor/bin/phpunit                                     # tout, une fois
```

Les controles de la CI, a jouer avant de pousser :

```bash
docker compose exec php php bin/console lint:yaml config --parse-tags
docker compose exec php php bin/console lint:container
docker compose exec php php bin/console lint:twig templates
docker compose exec php composer audit --locked
.claude/scripts/check-stack.sh && .claude/scripts/check-gouvernance.sh
```

Apres une migration, la base de test se met a jour comme celle de
developpement : `php bin/console doctrine:migrations:migrate --env=test`.

## Deploiement et versions

Un push sur `main` suffit : les portes qualite passent, puis le workflow ouvre
une connexion SSH dont la *forced command* lance `outils/deployer.sh prod` sur
le serveur — lequel tire la branche, construit l'image, joue les migrations et
verifie que le site repond depuis l'exterieur. Prealables, secrets a
renseigner, rollback et operations manuelles :
**[README.docker.md](README.docker.md)** et
**[docs/MISE-EN-PRODUCTION.md](docs/MISE-EN-PRODUCTION.md)**.

Le travail se fait sur `develop` (branches `feat/*` et `fix/*` fusionnees), puis
`develop` est fusionne dans `main` pour livrer. Une etiquette `v*` publie une
release GitHub dont le corps est la section correspondante de
[CHANGELOG.md](CHANGELOG.md) — procedure complete dans
[README.docker.md](README.docker.md), section « Publier une version ».

## Mises a jour

Modifier la version d'une image dans le `.env` racine, ouvrir une PR, laisser
le job `image` verifier que ca construit. Dependabot ouvre ses propositions
sur `develop`, jamais sur `main`.

> Montee de version majeure de PostgreSQL : `pg_dump` avant, suppression du
> volume, restauration ensuite. Le chemin de montage du volume depend de la
> version majeure (voir le commentaire dans `compose.yml`).

Dependances applicatives :

```bash
docker compose exec php composer outdated
cd app && npm outdated
```

## Donnees personnelles

Le depot est public : rien de ce qui est committe ne contient de secret,
d'adresse d'adherent ni de donnee reelle. Les regles de conservation,
d'anonymisation et d'acces sont dans
[docs/SECURITE-DONNEES.md](docs/SECURITE-DONNEES.md).

## Contribuer, signaler

- [CONTRIBUTING.md](CONTRIBUTING.md) — branches, commits, ce que la CI verifie.
- [SECURITY.md](SECURITY.md) — signaler une faille, en prive, jamais par issue.
- [CHANGELOG.md](CHANGELOG.md) — ce que chaque version change.

## Licence

[AGPL-3.0-or-later](LICENSE) — Copyright (C) 2026 Association Envie de Jouer et
Mylene Magny. Toute reutilisation du code pour un service en ligne doit en
publier les sources modifiees.
