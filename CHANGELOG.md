# Journal des versions

Format inspire de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
versions selon [SemVer](https://semver.org/lang/fr/).

Les sections publiees ici sont reprises telles quelles dans les releases
GitHub, par `.github/workflows/publication.yml`.

## [Non publie]

### Ajoute

- Import d'une liste d'adherents depuis un fichier Excel, en ligne de commande (`app:memberships:import`) ou depuis l'administration (menu « Import », super-administrateur) : analyse sans rien ecrire, compte rendu, puis confirmation.
  Simulation par defaut : le fichier est lu et compris, le compte rendu dit ce qui sera
  importe, ecarte ou refuse, rien n'est ecrit — pas meme un numero d'adherent.
  `--appliquer` ecrit, tout ou rien. Les colonnes se reconnaissent par leur en-tete ;
  celles sans usage dans la fiche (sexe, reseaux sociaux, lettre d'information,
  numero WhatsApp) sont nommees et laissees de cote. Le sens de la colonne des photos
  est un choix explicite (`--image=refus|accord`), jamais une valeur par defaut. Relancer
  un import est sans danger : une personne deja inscrite pour la saison est ecartee. Le
  compte rendu ne nomme personne (`--noms` pour les afficher). Le lecteur de classeurs,
  sans dependance, refuse archives et XML hostiles.
- L'adresse e-mail d'un bulletin est facultative hors du formulaire en ligne : une
  fiche papier ou un fichier repris peut decrire des personnes qui n'en ont pas — dans
  le fichier de l'association, pres d'un tiers. Elles ne recoivent ni confirmation ni
  lien d'acces, et le bureau les joint autrement. Obligatoire en ligne, comme avant.
- Passage d'un adherent consommateur a l'adhesion annuelle depuis l'administration :
  la fiche d'un consommateur de la saison en cours propose « Passer a l'adhesion
  annuelle ». C'est la meme adhesion, sous le meme numero — pas un renouvellement,
  qu'une saison n'autorise qu'une fois. On choisit la formule (individuelle, ou
  famille avec son adherent principal) et, si la cotisation est encaissee sur le
  moment, le moyen de reglement : l'adhesion est alors validee ; sinon elle repasse en
  attente, l'annuelle n'etant pas gratuite. Un enfant de moins de huit ans, qui ne
  doit rien, reste valide. Le changement figure dans l'historique de la fiche.

### Securite

- Formulaires publics durcis : un jeton horodate et signe, emis a l'affichage de la
  page, refuse un envoi parti en moins de trois secondes (un robot), falsifie ou reste
  ouvert plus de six heures ; il s'ajoute au piege a robots, a la protection CSRF et aux
  plafonds de debit, sans ouvrir de session pour chaque visiteur. Les champs de texte
  libre refusent aussi les caracteres de controle et les chevrons — de quoi glisser du
  balisage ou un retour a la ligne dans un courriel, un export ou un tableur —, sans
  toucher aux accents, apostrophes, tirets ni chiffres.
- Session de gestion coupee apres 30 minutes d'inactivite : un poste laisse ouvert sur
  la liste des adhesions ne montre plus de donnees personnelles a qui s'assoit
  devant. Le cookie de session de PHP ne dit rien de l'inactivite — sans cela, la
  session ne s'eteignait qu'a la fermeture du navigateur, c'est-a-dire jamais pour un
  onglet laisse ouvert.
- Sauvegardes chiffrables en AES-256 (`BACKUP_PASSPHRASE_FILE`) : le dump ne touche
  jamais le disque en clair, l'archive se relit — dechiffrement compris — avant d'etre
  gardee, et la restauration d'essai refuse une mauvaise phrase avec un message clair.
  Sans la variable, rien ne change. Voir `docs/SECURITE-DONNEES.md`, qui dit aussi ce
  qui n'est pas fait : double authentification, durcissement du serveur, anonymisation
  automatique.

## [1.3.0] - 2026-10-18

### Ajoute

- Numero d'adherent unique et stable, de la forme `EDJ-0001`. Il appartient a
  la personne et non au bulletin : une readhesion la saison suivante garde le
  meme numero, le rapprochement se faisant sur le nom, le prenom et la date de
  naissance normalises. Il figure sur la fiche d'administration, dans la liste
  et dans le courriel de confirmation.
- Suivi de l'etat d'une adhesion : en attente, validee ou annulee, avec le
  moyen de reglement de la cotisation (virement, especes, cheque) et
  l'administrateur qui l'a decide. Une adhesion annulee reste consultable ;
  c'est l'anonymisation, distincte, qui efface les donnees personnelles.
- Espace adherent, sans compte ni mot de passe : l'adherent saisit son numero
  sur `/adhesion/ma-fiche`, recoit a l'adresse de son bulletin un lien signe
  valable trente minutes, et consulte sa fiche. Aucune autre voie n'y mene, et
  la reponse est la meme qu'un numero existe ou non.
- Carte d'adherent dematerialisee, en PDF de deux pages au format d'une carte
  bancaire : recto reprenant le logo de l'association, verso avec le nom, le
  numero et les huit saisons. Telechargeable depuis l'espace adherent une fois
  l'adhesion validee.
- Tampons de saison, configures par le super-administrateur dans
  « Saisons » : un symbole (etoile, couronne, coeur) et une couleur (orange ou
  bleu) apposes sur la carte en face de chaque annee reglee, de quoi
  reconnaitre une carte a jour a l'entree d'un evenement. Les quatre premieres
  saisons sont posees d'apres les cartes papier en circulation (2024 etoile
  orange, 2025 couronne bleue, 2026 coeur orange, 2027 etoile bleue) ; les
  suivantes se saisissent, la rotation n'etant qu'une proposition.
- Visualisation de la carte d'un adherent depuis l'administration, sur sa
  fiche : la carte s'ouvre dans un onglet, meme avant validation, pour que le
  bureau la verifie ou la reimprime.
- Un oeil d'affichage sur tous les champs mot de passe (connexion et changement
  de mot de passe). Le bouton est revele par JavaScript : sans lui, le champ
  reste un champ mot de passe ordinaire.

- Tableau de bord a graphiques, pour la saison choisie : un chiffre en vedette
  (adherents a l'annee, compare a la saison precedente), les chiffres cles, puis
  six graphiques — adherents par saison (nouveaux, renouvellements,
  consommateurs ; cliquer une saison l'ouvre), cumul mois par mois contre la saison
  precedente, ages, origine geographique, formules et moyens de reglement — et les
  autorisations en jauges. Au survol ou au clavier, une infobulle donne les valeurs ;
  chaque graphique a sa vue en tableau, qui porte les memes nombres. Sans
  bibliotheque ni ressource externe : des SVG dessines cote serveur, sans style en
  ligne.
- Export de la liste des adhesions, filtree ou non, en CSV avec toutes les
  informations d'un bulletin : numero, saison, etat, moyen de reglement, montant
  du, identite, coordonnees, responsable legal, autorisations. Il suit les
  filtres de la liste, sans sa limite d'affichage, et chaque export est
  journalise (qui, combien de lignes) : le fichier sort du site avec des
  donnees personnelles, dont celles de mineurs.
- Trois filtres de plus sur la liste des adhesions : case Philibert, droit a
  l'image (photos refusees ou autorisees) et moyen de reglement (virement,
  especes, cheque, ou aucun). La liste s'ouvre desormais sur la saison en
  cours ; « Toutes les saisons » est un choix explicite.
- Renouvellement d'adhesion depuis le site : la page « Bulletin d'adhesion »
  propose de faire une nouvelle adhesion ou de renouveler la sienne. Pour
  renouveler, on saisit son numero d'adherent ; un lien signe, valable deux
  heures, part a l'adresse du dernier bulletin et ouvre le formulaire deja
  rempli, a relire et a signer de nouveau. Le renouvellement est un nouveau
  bulletin, en attente, rattache au meme numero — jamais un second adherent — et
  ne peut etre depose qu'une fois par saison. Le numero etant devinable, la
  reponse est la meme qu'il existe ou non.
- Renouvellement depuis l'administration : la fiche d'un ancien adherent propose
  « Renouveler pour la saison ... ». Le formulaire arrive rempli avec son dernier
  bulletin, et l'adhesion est enregistree sous le meme numero, comme fiche
  papier, en attente de la cotisation. Une saison ne se renouvelle qu'une fois —
  a l'ouverture du formulaire comme a l'enregistrement, la date de la fiche
  pouvant viser une autre saison —, et un bulletin anonymise ne peut pas etre
  repris.
- La fiche d'une adhesion montre le parcours de la personne : tous ses bulletins,
  saison apres saison, avec leur etat, leur formule et un lien vers chacun. Elle
  repond, devant un renouvellement, a « depuis quand adhere-t-elle ? ».
- Historique des decisions sur chaque adhesion — validation (avec le moyen de
  reglement), annulation, remise en attente, anonymisation : qui et quand,
  affiche discretement en bas de la fiche. Il ne garde aucune donnee de
  l'adherent et survit donc a l'anonymisation. Les bulletins existants
  conservent leur dernier changement d'etat ; les etapes intermediaires, qui
  n'avaient laisse aucune trace, ne peuvent pas etre retrouvees.
- Chiffres de la saison sur le tableau de bord, avec choix de la saison :
  adherents a l'annee, consommateurs, mineurs, age moyen, renouvellements et
  nouveaux, bulletins en attente et annules, repartition entre Joigny, le reste
  de la communaute de communes du Jovinien et le hors-territoire, et les deux
  autorisations. Tout se compte sur les adhesions **validees** : un bulletin
  depose mais non encaisse n'est pas une adhesion. Les communes de la
  communaute de communes sont un parametre (`app.territory.*`) : la source
  officielle n'en publie que 17 sur 19, les deux manquantes restent a ajouter.
- Filtres sur la liste des adhesions : recherche par nom, adresse e-mail ou
  numero — l'adherent principal est inclus, de sorte que le nom d'un parent
  remonte tout le foyer —, puis etat, formule et saison, combinables. Ils
  passent par l'URL : une liste filtree se partage par son lien.
- Le detail d'un bulletin famille dit si l'adherent principal saisi correspond
  a une adhesion individuelle connue, et renvoie vers elle.
- Anciens adherents fictifs pour le developpement : `app:memberships:demo
  --former=40` cree des personnes avec leur historique sur les saisons passees,
  sous un meme numero d'adherent, dans quatre situations face a la saison en
  cours — a renouveler, perdu de vue, deja renouvele, renouvellement depose mais
  non paye. Aucune donnee reelle : adresses en `@example.test`, retirees par
  `--purge`.
- Philibert rejoint la liste des partenaires, avec son logo (l'icone de marque,
  recuperee sur son site : la page d'accueil n'en portait alors qu'une variante
  saisonniere, qu'il ne fallait pas figer).

### Modifie

- Pages « Donnees personnelles » et « Mentions legales » remises d'aplomb sur ce
  que le site fait reellement : le numero d'adherent, l'empreinte d'identite,
  l'historique des decisions et le moyen de reglement ; l'espace adherent par lien
  personnel et la carte ; les statistiques et les exports ; ce que recoit
  Philibert (adresse et date d'adhesion, adherents a l'annee seulement) ; les
  cookies reels et le stockage local des preferences d'affichage ; les
  sauvegardes (quatorze jours) ; les liens vers des services tiers. La page
  affirmait qu'un cookie de session etait pose a l'envoi d'un formulaire : c'est
  faux depuis la protection sans session.
- Le suivi des adhesions passe du super-administrateur au contributeur : c'est
  le travail courant du bureau. Les comptes d'administration restent reserves
  au super-administrateur, qui seul en cree, bloque ou promeut.
- L'image PHP installe l'extension `gd`, exigee par la generation des cartes.

### Corrige

- La couleur des tampons de saison, dans l'administration, reposait sur un style
  en ligne que la politique de contenu refuse : en production, le symbole aurait
  perdu sa couleur sans la moindre erreur. Elle passe par une classe.
- Dans la liste des adhesions, la colonne « Etat et autorisations » ne montre plus
  que l'etat : le moyen de reglement et les autorisations se lisent sur la fiche, et
  se filtrent.
- L'export Philibert ne contient plus que les adherents actifs de la saison en
  cours, **hors consommateurs** — la formule gratuite a l'annee n'ouvre pas droit
  a l'avantage —, avec leur date d'adhesion (`email;date_adhesion`). Le bouton le
  dit : « Liste Philibert · adherents actifs 2027 ».
- La formule consommateur ne permet plus de cocher la communication a
  Philibert : la case se desactive sur le bulletin public, avec la raison, et le
  serveur le refuse aussi.
- L'export pour Philibert listait toute adresse autorisee, adhesion validee ou
  non, de toute saison. Il ne contient desormais que les adresses d'adherents
  dont l'adhesion est **validee pour la saison en cours**, sans doublon : la
  liste part chez un tiers et ne doit nommer que des adherents a jour.
- Un foyer qui partage une seule adresse e-mail — le cas courant, les parents
  saisissant la leur pour chacun de leurs enfants — perdait des courriels : a
  partir du quatrieme bulletin de la journee, la confirmation n'etait plus
  envoyee, sans que rien ne le signale. Les plafonds par destinataire sont
  desormais dimensionnes sur un grand foyer. Rien n'a jamais empeche de deposer
  plusieurs bulletins avec la meme adresse : c'est seulement l'envoi qui etait
  bride.
- Le montant du ne tenait pas compte de l'age : un enfant de moins de huit ans
  ne doit rien, quelle que soit la formule. Le montant se calcule desormais sur
  le bulletin — formule et age au depot — et non sur la seule formule, et une
  adhesion gratuite se valide sans moyen de reglement. Le detail d'une adhesion
  n'affiche plus le tarif de la formule, qui decrivait le foyer entier.
- Le bulletin acceptait qu'un adherent famille se designe lui-meme comme
  adherent principal : l'association encaissait 5 € la ou elle doit en
  encaisser 10. La formule famille se greffe sur l'adhesion individuelle d'une
  autre personne du foyer, et le bulletin le verifie.
- Le bulletin acceptait qu'un mineur prenne l'adhesion individuelle. Un mineur
  se rattache desormais obligatoirement a l'adhesion d'un adulte de son foyer,
  par la formule famille.
- Le bulletin d'adhesion n'affiche la zone « Responsable legal » que pour un
  adherent de moins de 18 ans : elle apparait des qu'une date de naissance de
  mineur est saisie, et reste visible sans JavaScript.
- Un lien pose dans un bloc de texte s'affichait en bleu souligne sur son
  bouton rouge (« Telecharger ma carte »), la regle des liens de texte l'emportant
  sur celle du bouton.
- L'introduction et la note de bas de page de l'espace adherent sont posees sur
  un panneau : le texte nu sur le quadrillage du fond se lisait mal.
- Sur telephone, l'encart jaune du calendrier recouvrait le titre « Les
  prochaines dates ». Le decor passe derriere le contenu et se reduit.
- Les filtres du calendrier passent sur deux colonnes de 48 px de haut sur
  telephone, annoncent leur groupe par une etiquette visible, et marquent le
  filtre actif d'une coche en plus de la couleur.
- Les grands titres (« Lien envoye », « jeux ») mordaient sur le texte qui les
  suit : leurs descendantes touchaient la ligne suivante. L'espace est elargi.
- Les documents a lire avant d'adherer (statuts, reglement, annexes) sont trois
  petits boutons cote a cote, qui passent a la ligne quand la place manque, au
  lieu de trois cartes.
- Deux bulletins d'un meme foyer deposes dans la meme seconde pouvaient
  changer la graphie de l'adresse retenue dans l'export Philibert d'une
  execution a l'autre. L'ordre est desormais departage par l'ordre de depot.
- A 320 px de large, la page d'accueil debordait de 5 px sur la droite :
  l'adresse e-mail du bouton de contact, d'un seul tenant, etait plus large que
  la colonne. Le bouton se plafonne a la largeur de son conteneur et l'adresse
  passe a la ligne.
- La liste des adhesions etait un tableau de sept colonnes, illisible sur un
  telephone : elle est doublee de cartes, une par bulletin, affichees seules
  en dessous de 768 px. Les cases a cocher et l'anonymisation en masse
  restent une action de bureau, sur le tableau — un champ duplique aurait
  envoye chaque identifiant deux fois.
- Cibles tactiles sous la taille minimale : les cases a cocher de la liste des
  adhesions passent de 20 a 24 px, et les liens de retour des pages
  d'administration et de connexion de 20 a 44 px de haut.
- Debordement horizontal de l'en-tete du site en dessous de 420 px : la marque
  et le bouton de menu ne tenaient pas cote a cote, et toutes les pages
  defilaient lateralement.
- Le conteneur `worker` ne se voyait injecter ni `MAILER_FROM`, ni
  `SITE_INDEXABLE`, ni `MEMBERS_WHATSAPP_URL` : comme c'est lui qui rend et
  envoie les courriels, chaque envoi echouait sur « Environment variable not
  found », etait rejoue trois fois puis abandonne. Aucun courriel ne partait,
  le conteneur restait demarre et rien ne le signalait.
- Une adhesion famille annoncait 10 € a regler sur la fiche d'administration et
  dans le courriel de confirmation, en reprenant le tarif du foyer (« 10 € par
  an, puis 5 € par personne supplementaire »). Le montant du est desormais
  celui de la personne : 5 € pour une adhesion famille, 10 € pour une adhesion
  individuelle, rien a l'annee pour la formule consommateur. Le tarif complet
  de la formule reste affiche a part.

### Securite

- Dependances de construction du front mises a jour (Webpack Encore 6,
  webpack-cli 6) : `npm audit` passe de 11 alertes a 0. Aucune n'atteignait le
  site en ligne, ces outils ne servent qu'a la compilation.

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
