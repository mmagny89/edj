# Journal des versions

Format inspire de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
versions selon [SemVer](https://semver.org/lang/fr/).

Les sections publiees ici sont reprises telles quelles dans les releases
GitHub, par `.github/workflows/publication.yml`.

## [Non publie]

### Ajoute

- Pages « Mentions legales » (`/mentions-legales`) et « Donnees personnelles »
  (`/donnees-personnelles`), reliees depuis le pied de page ; le bulletin
  d'adhesion porte desormais l'information de collecte exigee par l'article 13
  du RGPD. Le pied de page renvoyait jusqu'ici vers une page inexistante.
- Logos reels des cinq partenaires, servis depuis `public/img/partenaires/` et
  lies a leur site, en remplacement de la mascotte utilisee comme vignette
  d'attente.
- Tests du generateur de saison, qui n'en avait aucun.
- Le generateur de saison n'ecrit aucune date un jour ferie, mardis comme
  1ers samedis. Les feries viennent de l'API officielle du calendrier
  (calendrier.api.gouv.fr), conservee trente jours ; si elle ne repond pas, un
  calcul local prend le relais et la generation n'echoue pas.

- Administration des adhesions : detail d'un bulletin, fiche imprimable,
  anonymisation individuelle ou en masse (irreversible, nom et coordonnees
  effaces ; formule, autorisations et annee de naissance conservees) et export
  CSV des adresses a transmettre a Philibert — celles dont le titulaire a coche
  l'autorisation, jamais un bulletin anonymise, adresse seule.
- Courriel de confirmation envoye a l'adherent (et au responsable legal d'un
  mineur) au depot du bulletin, avec les reseaux sociaux publics et, si
  `MEMBERS_GROUP_URL` est renseignee sur le serveur, le lien du groupe prive.
  Cette variable est nouvelle : a declarer dans `.env.staging.local` et
  `.env.prod.local`, vide si le groupe n'existe pas, sinon `compose` refuse de
  demarrer.
- Commande `app:memberships:demo` : bulletins fictifs pour le developpement,
  refusee en production.
- Refonte de l'administration en Tailwind seul : theme Mosaic et Alpine
  retires (bundle de 4,4 a 1,5 Mio), menu lateral, icones, tableau de bord.
- Protection de l'administration : acces par role (les bulletins sont reserves
  au super-administrateur), cinq essais de connexion par minute, deconnexion
  par jeton, cookie de session durci, en-tetes de securite et politique de
  contenu a nonce.

### Modifie

- Les apres-midi mensuelles tombent desormais le **1er samedi** du mois et non
  plus le 3e : generation des dates (`SeasonGenerator`), libelles du
  back-office, du carrousel et de la page d'accueil.
- Le nombre de Nuits du Jeu annonce sur la page d'accueil passe de trois a
  quatre, en accord avec le reste de la page.
- La section « Rejoindre l'association » se lit en trois bandeaux ordonnes
  — ce que ca coute, ce que l'adhesion ouvre, comment adherer — au lieu de
  trois panneaux cote a cote. Les tarifs s'enoncent par ce qu'on paie :
  10 € par personne, + 5 € par personne supplementaire du meme foyer.

### Securite

- Montee de Symfony 8.0 vers 8.1 : la branche 8.0 ne recoit pas de correctif
  pour CVE-2026-109449 (expression-language), CVE-2026-108082
  (http-foundation) et CVE-2026-109413 (yaml), tous trois corriges a partir de
  8.1.6. `composer audit --locked` echouait donc la CI sans remede possible a
  constraint inchangee.

## [1.1.0] - 2026-09-12

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

[Non publie]: https://github.com/mmagny89/edj/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/mmagny89/edj/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/mmagny89/edj/releases/tag/v1.0.0
