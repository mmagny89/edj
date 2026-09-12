# Journal des versions

Format inspire de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
versions selon [SemVer](https://semver.org/lang/fr/).

Les sections publiees ici sont reprises telles quelles dans les releases
GitHub, par `.github/workflows/publication.yml`.

## [Non publie]

### Modifie

- Le deploiement se fait desormais sur le serveur (`outils/deployer.sh` appele
  par une forced command SSH) au lieu d'une image poussee sur GHCR et d'un
  Compose pilote a distance : plus de registre a gerer, et la logique de
  deploiement se relit avec le reste du code.

### Corrige

- `outils/deployer.sh` ignorait silencieusement sa verification externe sur un
  projet nommant son domaine `APP_DOMAIN` — soit exactement le controle pour
  lequel elle existe.

## [1.0.0] - 2026-09-12

Premiere version publiee : le site tel qu'il tourne, et la gouvernance d'un
depot public.

### Ajoute

- Site de l'association : presentation, calendrier des evenements avec dates
  recurrentes par saison, bulletin d'adhesion en ligne et notification par
  courriel, espace d'administration.
- Stack Docker FrankenPHP (mode worker) + PostgreSQL 18, en trois
  environnements (developpement, recette, production derriere Traefik).
- Consommateur Messenger dedie : envoi des courriels et taches planifiees
  (purge des evenements passes).
- Gouvernance du depot public : licence AGPL-3.0, politique de securite,
  guide de contribution, gabarits d'issue et de demande de fusion, Dependabot.
- Publication automatique des releases GitHub a partir de ce fichier, sur
  etiquette `v*`.
- Metriques Caddy sur une ecoute interne `:2020`, pour une supervision d'hote.
- Attrapeur de courriels Mailpit en developpement.

[Non publie]: https://github.com/mmagny89/edj/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/mmagny89/edj/releases/tag/v1.0.0
