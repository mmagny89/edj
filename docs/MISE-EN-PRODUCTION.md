# Mise en production — edj

Procedure complete, de la machine vierge au site en ligne, puis du domaine
provisoire (`edj.mmagny.fr`) au domaine definitif (`enviedejouer89.fr`).

Ce document ne repete pas ce que `README.docker.md` explique deja (architecture
du deploiement, conventions) : il donne **l'ordre des operations** et les
commandes, avec ce qu'il faut verifier a chaque etape.

## En resume

| Phase | Quoi | Duree |
|---|---|---|
| 0 | Points a regler **avant** de mettre en ligne (mentions legales : fait ; courriel Brevo) | 30 min |
| A | Preparer le VPS : Docker, Traefik, DNS, utilisateur | 30 min |
| B | Premier deploiement **manuel**, compte administrateur, controles | 30 min |
| C | Deploiement continu depuis GitHub | 20 min |
| D | Exploitation : sauvegardes, surveillance | 20 min |
| E | Bascule vers `enviedejouer89.fr` | 1 h + propagation DNS |

Regle d'or : **eprouver le deploiement manuel avant l'automatique**. Enchainer
les deux melerait deux sources d'echec, le stack et le dispositif SSH.

---

## Phase 0 — Avant de mettre en ligne

Ce sont des **obligations legales ou des fonctions qui ne marchent pas sans** :
a regler avant d'annoncer l'adresse a qui que ce soit.

### 0.1 Mentions legales — renseignees

Le RNA (W893006812), le SIRET (920 202 488 00028), la presidente (Celine
Gregoire), l'hebergeur (Hostinger International Ltd., Larnaca) et le credit de
conception (Mylene Magny, mmagny.fr) figurent dans
`app/templates/site/legal_notice.html.twig`.

**A verifier par vous**, que je ne peux pas confirmer depuis ici :

- L'adresse d'Hostinger vient du registre RIPE et de mentions legales de sites
  tiers, pas de la page officielle. La **LCEN** demande aussi le **numero de
  telephone** de l'hebergeur : le relever sur la page « Mentions legales » du
  site d'Hostinger et l'ajouter.
- Le centre de donnees du VPS est **en France** (confirme) : la page « Donnees
  personnelles » le dit, et aucune donnee ne quitte l'Union europeenne.

### 0.2 Courriel sortant : Brevo

Par defaut `MAILER_DSN=null://null` : les messages sont acceptes et **jetes**,
sans erreur ni journal. Le site envoie deux courriels a chaque bulletin (l'avis
au conseil d'administration, la confirmation a l'adherent) : il faut un vrai
transport **avant** d'ouvrir le formulaire au public.

**Piege : l'adresse d'expedition.** Brevo exige que le domaine de l'expediteur
soit **authentifie chez lui** (depuis fevrier 2024). Un domaine non authentifie
voit son expediteur **remplace** par une adresse `brevo...`, et une adresse
`gmail.com` ne peut jamais l'etre : le domaine n'est pas a vous. Le site separe
donc deux adresses :

| Variable | Role | Valeur |
|---|---|---|
| `MAILER_FROM` | **Expediteur** des courriels du site | une adresse sur un domaine authentifie dans Brevo |
| (`app.contact_email`) | Destinataire des avis, adresse de **reponse** | `enviedejouer89300@gmail.com` (inchange) |

L'adherent recoit donc un courriel de `MAILER_FROM`, et sa reponse arrive dans
la boite Gmail de l'association.

**Dans Brevo** (Parametres > Expediteurs, domaines et IP dédiées) :

1. **Domaines** > ajouter le domaine d'expedition : `mmagny.fr` en attendant,
   puis `enviedejouer89.fr` (phase E). Brevo donne des enregistrements DNS a
   creer chez le registrar : un TXT de verification (`brevo-code:...`), le
   **DKIM**, et on y ajoute un **DMARC** (`v=DMARC1; p=none; rua=mailto:...`
   suffit pour commencer). Attendre que Brevo affiche « Authentifie ».
2. **Expediteurs** > ajouter l'adresse choisie (`noreply@mmagny.fr`, par
   exemple) — elle n'a pas besoin d'exister comme boite de reception.
3. **Securite > Adresses IP autorisees** : sur les comptes recents, Brevo refuse
   les connexions d'une IP inconnue (« unauthorized: IP not authorized ») :
   **ajouter l'IP du VPS**.
4. **SMTP et API > SMTP** : relever le serveur `smtp-relay.brevo.com`, le port
   `587` et le **login** ; generer une **cle SMTP** (elle n'est affichee qu'une
   fois).

**Dans `.env.prod.local`** (phase B.2) :

```
MAILER_FROM=noreply@mmagny.fr
MAILER_DSN=smtp://LOGIN_BREVO:CLE_SMTP@smtp-relay.brevo.com:587
```

Si le login ou la cle contiennent `@`, `/`, `+` ou `:`, les encoder en
pourcentage (`@` devient `%40`). Le transport generique `smtp://` suffit : le
paquet `symfony/brevo-mailer` (`brevo+smtp://`) n'est pas installe.

L'offre gratuite de Brevo est limitee a ~300 courriels par jour, largement
au-dessus des besoins d'une association. Apres les premiers envois, controler
dans Brevo > Transactionnel > Journaux que les messages sont **delivres**, pas
seulement « envoyes ».

### 0.3 Lien du groupe WhatsApp (facultatif)

Si le lien d'invitation existe, `MEMBERS_WHATSAPP_URL=...` dans les secrets
(phase B). Vide : le courriel de confirmation ne cite que le groupe Facebook.
Ce lien **ne va jamais dans le depot** (public).

---

## Phase A — Le VPS existant

Le VPS (Hostinger) tourne deja avec Docker et un **Traefik partage** qui sert
d'autres sites. Il n'y a donc **rien a installer** : on verifie que le stack
pourra s'y brancher, **sans modifier ce qui existe**.

> Ne touchez ni a la configuration de Traefik, ni au pare-feu, ni aux autres
> projets : un Traefik partage est un point sensible, et ce dossier ne demande
> aucune modification de ces elements.

### A.1 Ce qui doit deja etre vrai

```sh
docker --version && docker compose version     # Docker et le plugin Compose
docker network ls | grep proxy                 # le reseau de Traefik (nom a relever)
sudo ufw status | head -5                      # 80 et 443 ouverts (deja le cas : d'autres sites y sont)
```

### A.2 Relever la configuration de Traefik

Trois valeurs dont `compose.prod.yml` depend, qui **se constatent** : le nom du
reseau, de l'entrypoint HTTPS, du resolveur ACME.

```sh
# apres le clone (phase B.1), ou en copiant le script sur le serveur
sh outils/diagnostic-traefik.sh
```

Le script lit les arguments de Traefik, ses variables, son fichier statique et
**les etiquettes des sites deja routes** — la source la plus sure. Comparer au
projet :

| Dans `compose.prod.yml` | Doit valoir (relever sur un site voisin) |
|---|---|
| `traefik.http.routers.edj-prod.entrypoints` | `websecure` ? |
| `traefik.http.routers.edj-prod.tls.certresolver` | `letsencrypt` ? |
| `PROXY_NETWORK` (dans `.env.prod.local`) | `proxy` ? |

Si un nom differe, **corriger `compose.prod.yml`** (ou `PROXY_NETWORK`) avant le
premier deploiement : un label que Traefik ignore ne produit **aucune erreur** —
le site ne repond pas, et les journaux du conteneur sont muets.

### A.3 DNS

`edj.mmagny.fr` est deja cree. Verifier depuis un autre poste :

```sh
dig +short edj.mmagny.fr      # doit afficher l'IP du VPS
```

### A.4 Utilisateur de deploiement

Si un utilisateur dedie n'existe pas deja pour les autres projets :

```sh
adduser --disabled-password --gecos "" deploiement
usermod -aG docker deploiement
mkdir -p /srv/sauvegardes && chown deploiement: /srv/sauvegardes
```

S'il existe (un utilisateur du groupe `docker` propre a ce projet), le reutiliser
convient. `root` fonctionnerait, mais une cle qui fuite s'executerait avec les
droits les plus eleves de la machine.

---

## Phase B — Premier deploiement, a la main

En tant qu'utilisateur `deploiement`.

### B.1 Cloner

Le dossier du clone **porte le nom du projet Compose** (`edj-prod`) : c'est la
convention du projet, et la *forced command* de la phase C en porte le chemin
absolu.

```sh
git clone git@github.com:mmagny89/edj.git /srv/edj-prod
cd /srv/edj-prod
git checkout main
```

Le depot est public : le clone fonctionne en HTTPS sans cle
(`https://github.com/mmagny89/edj.git`). Les cles de la phase C servent au
deploiement continu et a un eventuel depot prive.

### B.2 Secrets

```sh
cd /srv/edj-prod
sh outils/renseigner-secrets.sh prod
```

Le script copie `.env.prod.local.dist` en `.env.prod.local`, genere `APP_SECRET`,
et demande le reste. Valeurs :

| Variable | Valeur |
|---|---|
| `APP_DOMAIN` | `edj.mmagny.fr` |
| `POSTGRES_PASSWORD` | un mot de passe long, aleatoire (`openssl rand -base64 24`) ; **lu une seule fois**, a la creation du cluster |
| `SERVER_NAME` | `:80` (deja pose — ne pas y mettre le domaine) |
| `SYMFONY_TRUSTED_PROXIES` | `private_ranges` (deja pose) |
| `SITE_INDEXABLE` | `0` — **le domaine est provisoire** : `robots.txt` interdit tout et chaque page est en `noindex` |
| `MAILER_FROM` | l'adresse d'expedition de la phase 0.2 (`noreply@mmagny.fr` en attendant) — le script la demande |
| `MAILER_DSN` | le DSN Brevo de la phase 0.2 (a editer **a la main** apres le script : il est pre-rempli a `null://null`) |
| `MEMBERS_WHATSAPP_URL` | le lien d'invitation, ou vide |

Puis controler :

```sh
chmod 600 .env.prod.local
grep -c '=$' .env.prod.local      # variables obligatoires encore vides : doit etre 0 pour APP_SECRET, APP_DOMAIN, POSTGRES_PASSWORD
```

### B.3 Premier deploiement

```sh
sh outils/deployer.sh prod
```

Le script : verifie la branche, tire le code (`--ff-only`), construit l'image,
demarre la base seule, **joue les migrations avant la bascule**, demarre les
conteneurs, nettoie, puis interroge le site **depuis l'exterieur**. Il s'arrete
au premier echec (`set -e`).

Premier lancement : la construction de l'image prend plusieurs minutes.

### B.4 Creer le premier compte administrateur

Le site est en ligne mais **personne ne peut se connecter** : la base est vide.

```sh
docker compose -f compose.yml -f compose.prod.yml --env-file .env.prod.local \
  exec php php bin/console app:create-user vous@exemple.fr
```

(role `ROLE_SUPER_ADMIN` par defaut ; le mot de passe est demande, masque, 12
caracteres minimum). Les administrateurs suivants se creent ensuite depuis
**Administrateurs** dans l'interface.

### B.5 Controler de l'exterieur

Depuis votre poste (pas depuis le serveur) :

```sh
sh outils/verifier-site.sh edj.mmagny.fr
```

Il controle HTTPS, la redirection HTTP, les en-tetes de securite (HSTS, CSP),
l'indexation (doit etre **interdite** sur le domaine provisoire), les pages
privees, le 404 et la duree du certificat. **Tout doit passer** : il sort avec
un code non nul sinon.

Puis, a la main :

- [ ] `https://edj.mmagny.fr/` s'affiche, sans erreur dans la console du navigateur.
- [ ] Le calendrier est **vide** : normal, la base est neuve → etape suivante.
- [ ] Connexion a `/login` avec le compte cree.
- [ ] **Evenements > Generer la saison** : cree les mardis et les 1ers samedis (les jours feries sont ignores).
- [ ] Deposer un bulletin de test depuis le site public avec **votre** adresse : le conseil d'administration recoit l'avis, vous recevez la confirmation. S'il n'arrive rien : `MAILER_DSN` (voir Depannage).
- [ ] Supprimer ce bulletin de test : **Adhesions > Voir > Anonymiser**.

### B.6 Premiere sauvegarde, tout de suite

```sh
sh outils/sauvegarder.sh prod
sh outils/tester-restauration.sh prod
```

Voir la phase D pour l'automatiser.

---

## Phase C — Deploiement continu depuis GitHub

Une fois la phase B eprouvee. Chaque push sur `main` deploie.

### C.1 Cles et acces

```sh
cd /srv/edj-prod
sh outils/installer-deploiement.sh serveur         # une fois par machine
sh outils/installer-deploiement.sh projet prod     # cree la cle Actions + forced command
```

Le second affiche les **quatre secrets** a coller dans GitHub. La cle privee
n'est jamais affichee au terminal : le script indique le `cat` a lancer au
moment de la coller.

### C.2 Cote GitHub — pas a pas

Depot : `github.com/mmagny89/edj`. Les reglages se trouvent dans l'onglet
**Settings** (il faut etre proprietaire du depot).

**1. Les quatre secrets** — *Settings > Secrets and variables > Actions >
Secrets > New repository secret*. Les valeurs sortent de
`installer-deploiement.sh projet prod` (C.1) ; un secret par entree, nom exact :

| Secret | Contenu |
|---|---|
| `DEPLOIEMENT_HOTE` | L'adresse de connexion SSH du VPS — la **meme** que celle utilisee pour generer `known_hosts` (une IP et un nom sont deux entrees distinctes) |
| `DEPLOIEMENT_UTILISATEUR` | `deploiement` (ou l'utilisateur retenu en A.4) |
| `DEPLOIEMENT_CLE_PRIVEE` | La cle privee **entiere**, lignes `-----BEGIN` et `-----END` comprises, sans ligne en trop |
| `DEPLOIEMENT_KNOWN_HOSTS` | L'empreinte du serveur, une ligne |

Une valeur oubliee n'est pas signalee par GitHub (secret vide) : le workflow
controle leur presence avant de s'en servir et nomme ceux qui manquent.

**2. L'environnement `production`** — *Settings > Environments > New
environment* > nom exact `production`. Le workflow le reference : sans lui, le
job echoue ou en cree un vide. Option recommandee : cocher **Required
reviewers** et vous designer — chaque deploiement attend alors votre clic
« Approve », ce qui evite qu'une fusion distraite parte en ligne.

**3. La protection de `main`** — *Settings > Branches > Add branch protection
rule* > pattern `main` :

- **Require a pull request before merging** ;
- **Require status checks to pass before merging**, puis chercher et cocher
  **`Portes qualité`** (le job agregateur du workflow ; il n'apparait dans la
  liste qu'apres une premiere execution du workflow) ;
- **Do not allow bypassing the above settings**.

Un seul controle requis : ajouter ou renommer une porte ne casse plus la regle.

**4. Les actions GitHub** — *Settings > Actions > General* : « Allow all
actions » ou « Allow actions created by GitHub + verified creators », et
« Workflow permissions » en lecture seule (le workflow n'ecrit pas dans le
depot).

### C.3 Premier essai automatique

Actions > « Qualite et deploiement » > **Run workflow** sur `main`
(`workflow_dispatch`). Lire la sortie du job `Deploiement en production` : c'est
celle de `deployer.sh`, relayee.

Ensuite : un push sur `develop` ne deploie **rien** ; seule une fusion dans
`main` le fait.

---

## Phase D — Exploitation

### D.1 Sauvegardes quotidiennes

```sh
crontab -e     # en tant que deploiement
17 3 * * * cd /srv/edj-prod && sh outils/sauvegarder.sh prod >> /var/log/edj-sauvegarde.log 2>&1
```

Les 14 plus recentes sont gardees dans `/srv/sauvegardes/edj-prod` (dossier 0700,
fichiers 0600 : ils contiennent des donnees personnelles, dont celles de
mineurs).

**Deux choses que le script ne fait pas** :

1. **Copier ailleurs.** Une sauvegarde qui reste sur le serveur ne protege pas de
   la perte du serveur. Copier `/srv/sauvegardes/edj-prod` vers une autre machine
   ou un stockage objet (`rclone`, `rsync`, snapshot du fournisseur).
2. **Tester la restauration** : `sh outils/tester-restauration.sh prod` restaure
   dans une base jetable, compte les lignes et la supprime ; la base en service
   n'est pas touchee. A rejouer chaque trimestre.

Retention RGPD : la page « Donnees personnelles » annonce l'anonymisation trois
ans apres la derniere adhesion. Les sauvegardes contiennent les donnees telles
qu'elles etaient : une anonymisation n'efface pas les anciennes sauvegardes — a
garder en tete pour la duree de conservation (14 jours).

### D.2 Surveillance

- **Disponibilite** : un controle externe gratuit (UptimeRobot, Better Stack) sur
  `https://<domaine>/` toutes les 5 minutes, alerte par courriel.
- **Le worker** consomme la file des courriels et la purge nocturne des
  evenements : s'il tombe, **rien ne signale l'erreur**, mais plus aucun courriel
  ne part. `docker compose ... ps` doit montrer `worker` en `running`.
- **Espace disque** : `df -h` ; `deployer.sh` supprime les images orphelines.
- **Certificat** : renouvele par Traefik ; `verifier-site.sh` alerte a moins de 14 jours.

### D.3 Mises a jour

- Code de l'application : fusion dans `main` → deploiement automatique.
- Systeme : `unattended-upgrades` (A.1) ; redemarrer de temps en temps.
- Dependances : `composer audit --locked` est une porte de la CI.

### D.4 Retour arriere

Voir `README.docker.md`, section « Rollback ». Les migrations ne se rejouent
pas a l'envers : un retour qui traverse une migration destructrice demande une
restauration de sauvegarde — d'ou la sauvegarde **avant** une livraison risquee :

```sh
sh outils/sauvegarder.sh prod && git push origin main
```

---

## Phase E — Bascule vers `enviedejouer89.fr`

A faire quand le domaine est achete et que le site a ete valide sous
`edj.mmagny.fr`.

### E.1 La veille

- Baisser le **TTL** de l'enregistrement DNS a 300 s, 24 h avant, pour une
  bascule rapide et reversible.
- Creer l'enregistrement chez le registrar de `enviedejouer89.fr` :

  ```
  enviedejouer89.fr.       A   <IP du VPS>
  www.enviedejouer89.fr.   A   <IP du VPS>   (ou CNAME vers le domaine nu)
  ```

- Verifier : `dig +short enviedejouer89.fr`.

### E.2 Courrier du domaine (a prevoir tot)

Si l'association veut `contact@enviedejouer89.fr` :

1. Creer la boite chez le registrar ou un hebergeur de messagerie.
2. Poser **SPF**, **DKIM** et **DMARC** dans le DNS (donnees fournies par le
   fournisseur) : sans eux, les confirmations d'adhesion finissent en
   indesirables. Un sous-domaine n'herite pas de l'authentification de son
   parent cote fournisseur.
3. Authentifier `enviedejouer89.fr` dans Brevo (phase 0.2, etape Domaines),
   passer `MAILER_FROM` a `contact@enviedejouer89.fr` dans `.env.prod.local`
   et, si la boite devient l'adresse de contact, changer `app.contact_email` dans
   `app/config/services.yaml` ainsi que l'adresse affichee sur le site
   (`app/templates/site/index.html.twig`, `legal_notice.html.twig`,
   `privacy.html.twig`). Committer, fusionner.

Cette etape peut venir **apres** la bascule : l'adresse Gmail continue de
fonctionner.

### E.3 Le jour J

Dans `/srv/edj-prod/.env.prod.local` :

```
APP_DOMAIN=enviedejouer89.fr
ANCIEN_DOMAINE=edj.mmagny.fr
SITE_INDEXABLE=1
```

- `APP_DOMAIN` : le domaine principal (routeur Traefik).
- `ANCIEN_DOMAINE` : `deployer.sh` ajoute alors `compose.redirection.yml`, qui
  redirige **de façon permanente (301)** `edj.mmagny.fr` vers le nouveau domaine,
  chemin conserve. Les adresses deja partagees continuent de marcher, et les
  moteurs apprennent le deplacement.
- `SITE_INDEXABLE=1` : ouvre le site aux moteurs de recherche (`robots.txt`
  autorise l'accueil, pages en `index`, plan du site annonce).

Puis :

```sh
cd /srv/edj-prod
sh outils/deployer.sh prod          # ou un push sur main, ou « Run workflow »
```

Traefik obtient les certificats pour les deux domaines (le DNS de l'ancien doit
pointer encore sur le serveur). Controler :

```sh
sh outils/verifier-site.sh enviedejouer89.fr --indexable
curl -sI https://edj.mmagny.fr/adhesion/bulletin | grep -iE '^(HTTP|location)'
#  HTTP/2 301
#  location: https://enviedejouer89.fr/adhesion/bulletin
```

Rien d'autre a changer cote code : l'URL canonique, le plan du site, les
donnees structurees, les liens des courriels et l'image de partage se
construisent depuis le domaine de la requete.

### E.4 Apres la bascule

- [ ] **Google Search Console** : ajouter `enviedejouer89.fr` en propriete de
      domaine (validation par un enregistrement DNS TXT), soumettre
      `https://enviedejouer89.fr/sitemap.xml`. Si `edj.mmagny.fr` y figurait,
      utiliser « Changement d'adresse ».
- [ ] Mettre a jour l'adresse du site sur la **page Facebook**, le profil
      **Instagram**, la fiche MyLudo et les documents imprimes.
- [ ] Garder `ANCIEN_DOMAINE` **au moins six mois** (les moteurs et les liens
      externes mettent du temps a suivre), puis retirer la ligne : la
      redirection disparait au deploiement suivant, et l'enregistrement DNS de
      l'ancien domaine peut etre supprime.
- [ ] Remonter le TTL DNS a une valeur normale (3600 s).
- [ ] Ajouter `enviedejouer89.fr` a la surveillance de disponibilite (D.2).

### E.5 Revenir en arriere

Remettre l'ancien `APP_DOMAIN`, retirer `ANCIEN_DOMAINE`, `SITE_INDEXABLE=0`,
redeployer. Le TTL bas de la veille rend le DNS reversible en quelques minutes.

---

## Depannage

| Symptome | Cause probable | Verification |
|---|---|---|
| `docker compose` refuse de demarrer : « variable ... manquante » | Une variable obligatoire est vide dans `.env.prod.local` | `grep '=$' .env.prod.local` |
| Le deploiement s'arrete a « Mise a jour du schema » | Migration en echec : l'**ancien code continue de servir** (c'est voulu) | `docker compose ... run --rm --no-deps php php bin/console doctrine:migrations:status` |
| 502 / 504 depuis le navigateur, conteneur `healthy` | Reseau Traefik ou label `traefik.docker.network` | `sh outils/diagnostic-traefik.sh` |
| Boucle de redirection infinie | `SERVER_NAME` contient le domaine au lieu de `:80` | `grep SERVER_NAME .env.prod.local` |
| Apres connexion, l'ecran de connexion reste affiche | Proxies de confiance absents : URL generees en `http://` | `SYMFONY_TRUSTED_PROXIES=private_ranges` ; `curl -sI https://<domaine>/admin \| grep -i location` doit dire `https` |
| Aucun courriel ne part | `MAILER_DSN=null://null`, ou le worker est arrete | `ps` (worker `running`) ; `logs worker` ; `MAILER_DSN` |
| Certificat non delivre | DNS pas encore propage, ou port 80 ferme | `dig +short <domaine>` ; `ufw status` ; logs de Traefik |
| Le site n'apparait pas dans Google | `SITE_INDEXABLE=0` (domaine provisoire) | Normal jusqu'a la phase E |
| `verifier-site.sh` : « politique de contenu absente » | Le serveur tourne en mode debug | `APP_ENV` doit etre `prod` dans l'image |
| `git merge --ff-only` echoue au deploiement | Le clone du serveur a ete modifie a la main | `git status` dans `/srv/edj-prod` : un humain doit regarder |

## Variables de `.env.prod.local`

| Variable | Obligatoire | Role |
|---|---|---|
| `APP_SECRET` | oui | Secret Symfony (signature) — genere par `renseigner-secrets.sh` |
| `APP_DOMAIN` | oui | Domaine public (routeur Traefik) |
| `POSTGRES_PASSWORD` | oui | Mot de passe de la base — lu a la **creation** du cluster seulement |
| `SERVER_NAME` | oui | `:80` — jamais le domaine |
| `SYMFONY_TRUSTED_PROXIES` | oui | `private_ranges` derriere Traefik |
| `MAILER_FROM` | **oui** | Expediteur des courriels : adresse sur un domaine authentifie dans Brevo (phase 0.2) |
| `MAILER_DSN` | non | Transport des courriels ; `null://null` n'envoie rien. Brevo : `smtp://LOGIN:CLE@smtp-relay.brevo.com:587` |
| `MEMBERS_WHATSAPP_URL` | non | Lien du groupe WhatsApp, dans le courriel de confirmation |
| `SITE_INDEXABLE` | non | `0` (defaut) : invisible des moteurs ; `1` : ouvert |
| `ANCIEN_DOMAINE` | non | Active la redirection 301 de l'ancien domaine |
| `PROXY_NETWORK` | oui | Nom du reseau Docker de Traefik (`proxy`) |
