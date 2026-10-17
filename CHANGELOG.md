# Journal des versions

Format inspire de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
versions selon [SemVer](https://semver.org/lang/fr/).

Les sections publiees ici sont reprises telles quelles dans les releases
GitHub, par `.github/workflows/publication.yml`.

## [Non publie]

## [1.2.0] - 2026-10-17

### Corrige

- Les liens Statuts, Reglement interieur et Annexes pointaient vers des PDF
  absents du depot (erreur 404) : ils ouvrent maintenant les documents
  heberges sur Google Drive, dans un nouvel onglet.
- Mise en production : suppression des options de proxy Doctrine retirees par
  ORM 3, qui empechaient toute commande de console en `prod`.

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
  mineur) au depot du bulletin, avec les reseaux sociaux publics, le groupe
  Facebook des adherents et, si `MEMBERS_WHATSAPP_URL` est renseignee sur le
  serveur, le lien du groupe WhatsApp.
  Cette variable est nouvelle : a declarer dans `.env.staging.local` et
  `.env.prod.local`, vide s'il n'y a pas de groupe WhatsApp, sinon `compose` refuse de
  demarrer.
- Un destinataire ne recoit plus que trois courriels de confirmation par
  jour : saisir l'adresse d'un tiers dans le bulletin ne permet plus de
  l'inonder depuis des IP differentes.
- Administration : textes gris assombris (contraste WCAG AA) et cases de
  selection des adhesions nommees pour les lecteurs d'ecran ; axe-core ne
  releve plus aucune erreur sur les neuf pages.
- En-tetes de securite sur tout le site, plus seulement l'administration :
  politique de contenu stricte a nonce (aucun script ni style en ligne, aucune
  ressource externe), HSTS, interdiction d'etre place dans un cadre,
  Referrer-Policy et Permissions-Policy.
- Inscription d'un adherent depuis l'administration (`/admin/adhesions/nouveau`),
  pour une personne qui a rempli une fiche papier : meme donnees que le
  bulletin en ligne, date de la fiche, attestation obligatoire que le papier
  signe existe, et choix d'envoyer ou non le courriel de confirmation. Le
  bulletin est marque « fiche papier » et garde l'administrateur qui l'a saisi.
- Gestion des administrateurs (`/admin/utilisateurs`, super-administrateur) :
  creer un compte avec un mot de passe provisoire affiche une seule fois,
  bloquer et debloquer, changer le role, reinitialiser un mot de passe. Un
  compte bloque ne se connecte plus et sa session ouverte est coupee a la
  requete suivante, de meme qu'apres un changement de role ou de mot de passe.
  On ne touche pas a son propre compte, et le dernier super-administrateur
  actif est protege. Chacun change son mot de passe depuis « Mon compte », et
  doit le faire avant tout autre ecran tant que le sien est provisoire.
  Migration additive : les comptes existants apparaissent « crees » a la date de
  la migration.
- Commande `app:memberships:demo` : bulletins fictifs pour le developpement,
  refusee en production.
- Refonte de l'administration en Tailwind seul : theme Mosaic et Alpine
  retires (bundle de 4,4 a 1,5 Mio), menu lateral, icones, tableau de bord.
- Protection de l'administration : acces par role (les bulletins sont reserves
  au super-administrateur), cinq essais de connexion par minute, deconnexion
  par jeton, cookie de session durci, en-tetes de securite et politique de
  contenu a nonce.

### Modifie

- Mentions legales completees (RNA, SIRET, presidente, hebergeur Hostinger,
  credit de conception) et signature « Site cree par Mylene Magny » en pied de
  page ; la page « Donnees personnelles » nomme ses deux sous-traitants,
  Hostinger et Brevo.
- Courriels : l'expediteur (`MAILER_FROM`) est separe de l'adresse de contact.
  Brevo exige un domaine authentifie chez lui et remplace sinon l'expediteur ;
  une adresse gmail.com ne peut jamais l'etre. L'association continue de
  recevoir et de repondre sur sa boite habituelle. Variable obligatoire en
  staging et en production : a declarer dans `.env.prod.local`.
- Mise en production : procedure complete dans `docs/MISE-EN-PRODUCTION.md`
  (preparation du VPS, premier deploiement, deploiement continu, exploitation,
  bascule d'un domaine provisoire vers le domaine definitif), et trois outils :
  `sauvegarder.sh` (dump controle, rotation, droits fermes),
  `tester-restauration.sh` (restauration dans une base jetable) et
  `verifier-site.sh` (controle de recette depuis l'exterieur).
- Domaine provisoire : `SITE_INDEXABLE` (0 par defaut) tient le site hors des
  moteurs de recherche — `robots.txt` interdit tout, pages en `noindex` — jusqu'a
  la mise en ligne sous le domaine definitif. `ANCIEN_DOMAINE` active une
  redirection 301 de l'ancien domaine vers le nouveau.
- Reglages d'accessibilite du site public : un bouton d'en-tete ouvre un panneau
  de quatre options, memorisees dans le navigateur — contraste renforce (le
  rouge de la charte, a 3,15:1 sous du texte blanc, passe a un rouge a plus de
  5:1 ; la preference systeme « contraste eleve » l'active d'office), texte
  20 % plus grand, liens soulignes, sans animation —, avec un bouton pour tout
  reinitialiser. La charte reste intacte par defaut. Un lien d'evitement est
  ajoute.
- Referencement : description, URL canonique, Open Graph et Twitter sur chaque
  page, donnees structurees schema.org (l'association et ses dix prochains
  evenements), `robots.txt` et `sitemap.xml` generes par l'application, image de
  partage 1200x630 et page 404 dediee. Hors production, tout est en `noindex`
  et `robots.txt` interdit tout : la recette ne concurrence pas le vrai site.
- Polices et icones servies par le site (Akaya Kanadaka, Atkinson
  Hyperlegible, Font Awesome) : plus aucune requete vers Google ni un CDN, ce
  qui rend vraie la page « Donnees personnelles ».
- Bulletin d'adhesion : les champs obligatoires portent un asterisque — nom,
  prenom, date de naissance, code postal, ville, e-mail, formule, declarations
  et signature —, et ceux qui ne le sont que selon le cas le deviennent a
  l'ecran : le membre de la famille pour la formule famille, les trois champs
  du responsable legal pour un adherent de moins de 18 ans.
- Bulletin d'adhesion, choix de la formule : trois cartes qui disent POUR QUI
  elles sont (« Je viens seul(e) », « Un membre de ma famille a deja pris
  l'adhesion a 10 € », « Je veux seulement payer a chaque evenement ») et ce que
  la personne paie (10 €, 5 €, gratuit puis 1 € par evenement). Le champ « membre
  de votre famille qui a deja adhere » n'apparait que pour la formule famille.
- Bulletin d'adhesion : cases a cocher et boutons radio redessines — la case
  precede son libelle sur la meme ligne au lieu de se perdre dessous, avec un
  etat coche lisible et un focus visible. Le bouton « Contraste » de l'en-tete
  n'affiche plus que son icone, avec une infobulle au survol et au clavier.
- Le site ne parle plus d'une ludotheque de l'association, qui n'en a pas :
  les jeux sont ceux de ses membres, et la section « L'association » le dit
  (le mode d'emploi en trois etapes, et le chiffre de 1 300 jeux mis en avant). Les descriptions par defaut des evenements sont
  reformulees, et une migration remplace l'ancien texte la ou il n'a pas ete
  reecrit a la main.
- La zone « Nos rendez-vous » est refaite : une carte par rendez-vous, avec son
  rythme (chaque semaine, chaque mois, quatre fois par an, une fois par an) en
  etiquette, ses horaires, puis les sorties hors de nos murs.
- Les apres-midi mensuelles tombent desormais le **1er samedi** du mois et non
  plus le 3e : generation des dates (`SeasonGenerator`), libelles du
  back-office, du carrousel et de la page d'accueil.
- Le nombre de Nuits du Jeu annonce sur la page d'accueil passe de trois a
  quatre, en accord avec le reste de la page.
- La section « Rejoindre l'association » se lit en trois bandeaux ordonnes
  — ce que ca coute, ce que l'adhesion ouvre, comment adherer — au lieu de
  trois panneaux cote a cote. Les tarifs s'enoncent par ce qu'on paie :
  10 € par personne, + 5 € par personne supplementaire du meme foyer.

### Corrige

- Deploiement : un `PROXY_NETWORK` qui ne designe pas le reseau reel de Traefik
  ne faisait echouer le deploiement qu'apres la construction de l'image, sur
  « network ... declared as external, but could not be found ». `deployer.sh`
  controle desormais ce reseau des le debut, nomme celui qui manque et liste les
  reseaux presents.
- Schema : `doctrine:schema:validate` echouait apres les migrations. Les colonnes
  `id` de `user` et de `messenger_messages` etaient des SERIAL alors que Doctrine
  attend des colonnes d'identite, `messenger_messages` portait trois index au lieu
  du seul que Messenger attend, et l'index de date des bulletins n'etait pas
  declare dans l'entite. Une migration realigne le tout et repositionne les
  sequences sur `max(id) + 1` (sans cela, la premiere insertion reprenait l'id
  d'une ligne existante) ; essayee sur une base avec des comptes, montee puis
  descendue puis remontee.
- Integration continue : les tests fonctionnels rendent de vraies pages et ont
  besoin du build Webpack (`entrypoints.json`) ; la porte des tests le construit
  desormais, au lieu d'echouer en cascade sur vingt-cinq tests.
- Deploiement : les migrations passaient apres la bascule du code. Le code neuf
  lisait des colonnes que l'ancien schema n'avait pas, et chaque depot de
  bulletin ou page d'administration concernee repondait 500 entre le demarrage
  et la migration. Elles jouent desormais avant la bascule, sur la base seule ;
  une migration doit rester compatible avec le code precedent.
- Deploiement : `MEMBERS_WHATSAPP_URL`, facultative, etait declaree comme un
  secret obligatoire : son absence du fichier de secrets faisait echouer
  `docker compose` avant meme les migrations. Elle est desormais vide par
  defaut.

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
