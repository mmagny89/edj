# edj — site de l'association Envie de Jouer

Depot **public** (AGPL-3.0). Rien de ce qui est committe ne doit contenir de
secret, d'adresse personnelle d'adherent ni de donnee reelle.

## Stack

Symfony 8 (PHP 8.4) sur FrankenPHP en mode worker, PostgreSQL 18, Webpack
Encore + Tailwind 4, Turbo/Stimulus. Tout le code applicatif vit dans `app/` ;
la racine ne porte que l'infrastructure.

## Commandes

Toujours par la cle de service nue, jamais par le `container_name` :

- Demarrer : `docker compose up -d --wait`
- Console : `docker compose exec php php bin/console <...>`
- Tests : `docker compose exec php vendor/bin/phpunit`
- Lints (ceux de la CI) : `php bin/console lint:yaml config --parse-tags`,
  `lint:container`, `lint:twig templates`, `composer audit --locked`
- Front : `cd app && npm run watch` — **depuis l'hote, jamais depuis le
  conteneur** (binaire natif lightningcss propre a la plateforme)
- Controles avant de rendre la main : `.claude/scripts/check-stack.sh` et
  `.claude/scripts/check-gouvernance.sh`

En developpement, `npm run build` (mode production) casse l'affichage tant que
`cache:clear` n'a pas ete joue : utiliser `npm run dev` ou `npm run watch`.

## Conventions

- Infrastructure Docker : `.claude/rules/stack-conventions.md` fait autorite,
  y compris contre toute skill. Ne pas la reformuler ici.
- Code PHP/Twig : skill `symfony-coding-standards`. Tests : skill
  `phpunit-testing-standards`. Gabarits : skill `tailwind-css-standards`.
  Commits et branches : skill `git-commit-conventions` — **jamais de
  co-auteur ni de mention d'outil dans un commit de ce depot**.
- Workflows CI/CD : skill `ci-pipeline-standards`.
- Le projet est redige **sans accent dans les commentaires de code, les
  messages de commit et les fichiers d'infrastructure** ; les gabarits Twig
  visibles du public, eux, sont en francais accentue correct.

## Architecture

- Deux conteneurs applicatifs partagent la meme image : `php` (le site) et
  `worker` (consommateur Messenger). Les courriels passent par le transport
  `async` et les taches recurrentes par `App\Schedule` : sans le worker, rien
  ne part et aucune tache ne tourne — et aucune erreur ne le signale.
- `RUN_MIGRATIONS` vaut 1 en dev et recette, 0 en production, ou la migration
  est une etape explicite du deploiement.
- Les conteneurs, reseaux et volumes portent le suffixe `${ENV}` :
  `edj-dev-php`, `edj-prod-database-data`. Recette et production peuvent ainsi
  cohabiter sur un meme hote sans partager de volume.
- Le deploiement construit l'image sur le runner GitHub, la pousse sur GHCR et
  pilote Compose a distance (`DOCKER_HOST=ssh://`) : le serveur ne construit
  jamais rien et ne porte ni clone ni fichier de secrets. Ecart assume aux
  conventions (section 22), documente dans `README.docker.md`.
- Une etiquette `v*` publie une release GitHub dont le corps est la section
  correspondante de `CHANGELOG.md` (`publication.yml`). Completer la section
  « Non publie » fait partie d'un changement visible de l'utilisateur.

## Pieges connus

- Une variable injectee par `compose.yml` (`DATABASE_URL`, `APP_ENV`,
  `MAILER_DSN`, `SYMFONY_TRUSTED_PROXIES`) ne doit jamais etre redefinie dans
  `app/.env` : la valeur concurrente est masquee en conteneur et reprend la
  main hors conteneur, sur une valeur fausse. Elle doit en revanche exister
  dans `app/.env.test` et dans le `env:` du workflow, qui ne passent pas par
  Compose — sinon la compilation du conteneur echoue sur « Environment
  variable not found » dans tous les jobs.
- Derriere Traefik, un conteneur `healthy` ne prouve rien : la verification se
  fait de l'exterieur (`curl -sI https://edj.mmagny.fr/`).
- `POSTGRES_PASSWORD` n'est lu qu'a la premiere initialisation du cluster ; le
  changer ensuite ne change pas le mot de passe de la base existante.
- Aucun transport de courriel reel n'est configure a ce jour : `null://null` en
  production, Mailpit en developpement (http://127.0.0.1:8025).
