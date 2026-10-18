# Securite des donnees des adherents

Ce que le site protege, contre quoi, ce qui est en place, et ce qui reste a faire — dans
l'ordre ou cela compte. Le depot est public : ce document ne contient aucun secret ni
aucune donnee reelle.

## Ce qu'on protege, et ce qui peut arriver

La base porte des noms, adresses, telephones, dates de naissance — dont celles de
mineurs —, les moyens de reglement et l'historique des decisions du bureau. Les trois
accidents realistes, du plus frequent au plus rare :

1. **Une copie qui s'egare** : sauvegarde envoyee sur un stockage mal regle, disque ou
   cle USB perdus, export CSV laisse dans un dossier « Telechargements » ou envoye par
   courriel. C'est, de loin, la fuite la plus courante dans une association.
2. **Un compte d'administration compromis** : mot de passe reutilise ailleurs,
   hameconnage, poste laisse ouvert.
3. **Le serveur lui-meme** : acces SSH vole, faille d'un logiciel non mis a jour.

## En place

| Protection | Ou |
|---|---|
| HTTPS partout, HSTS, cookies `Secure` / `HttpOnly` / `SameSite` | `SecurityHeadersSubscriber`, `framework.yaml` |
| Politique de contenu stricte (pas de script ni de style en ligne), `frame-ancestors none`, pages de gestion jamais mises en cache | `SecurityHeadersSubscriber` |
| Protection CSRF sans session (aucune session ouverte pour un visiteur anonyme) | `csrf.yaml` |
| Formulaires publics : piege a robots, jeton horodate et signe, plafonds de debit par IP et par adresse, caracteres de controle et chevrons refuses | `FormGuard`, `MembershipApplicationType`, `rate_limiter.yaml` |
| Connexion : 5 essais par minute, mots de passe de 12 caracteres au moins et non triviaux, changement force du mot de passe provisoire | `security.yaml`, `ChangePasswordType` |
| **Session de gestion coupee apres 30 minutes d'inactivite** | `IdleSessionSubscriber` |
| Un compte bloque ou dont le role change perd ses sessions ouvertes a la requete suivante | `User::isEqualTo` |
| Fiche et carte d'adherent : pas de compte, un lien signe de 30 minutes (2 heures pour renouveler), identique que le numero existe ou non | `MemberAccessLink` |
| Comptes d'administration : seul le super-administrateur les cree, bloque ou promeut | `UserAdminController` |
| Journal des actions sensibles (validation, annulation, anonymisation, export, creation de compte) par identifiant, jamais par adresse e-mail | `monolog.logger.security` |
| Historique des decisions sur chaque fiche : qui, quand | `MembershipEvent` |
| Anonymisation irreversible (nom, coordonnees, signature, empreinte d'identite) | `MembershipApplication::anonymize` |
| Aucune donnee personnelle dans les journaux ni dans le depot (donnees de demonstration en `@example.test`) | conventions du projet |
| Base de donnees non publiee sur l'hote en production | `compose.prod.yml` |
| Secrets hors du depot (`.env.prod.local`), jamais dans l'image | conventions du projet |
| Audit des dependances a chaque integration | `composer audit` en CI |
| **Sauvegardes chiffrables en AES-256**, dump jamais ecrit en clair | `outils/sauvegarder.sh` |

## A faire, par priorite

### 1. Chiffrer les sauvegardes et les copier AILLEURS

C'est le geste qui protege le plus, pour le moins d'effort : la fuite la plus probable
est une copie.

```sh
# Une fois, sur le serveur : une phrase aleatoire, lisible de l'exploitant seul.
umask 077
head -c 48 /dev/urandom | base64 > /root/edj-sauvegarde.phrase
chmod 400 /root/edj-sauvegarde.phrase

# La sauvegarde quotidienne devient chiffree (cron, a la place de la ligne actuelle) :
17 3 * * * cd /srv/edj-prod && BACKUP_PASSPHRASE_FILE=/root/edj-sauvegarde.phrase sh outils/sauvegarder.sh prod >> /var/log/edj-sauvegarde.log 2>&1
```

- **Conserver la phrase ailleurs que sur le serveur** (gestionnaire de mots de passe).
  Sans elle, une sauvegarde est irrecuperable — c'est voulu, et c'est pour cela qu'il
  faut la garder en deux endroits.
- **Tester la restauration** avec la phrase :
  `BACKUP_PASSPHRASE_FILE=/chemin/phrase sh outils/tester-restauration.sh prod`.
- La phrase est sur le meme serveur que les sauvegardes : elle protege la copie envoyee
  ailleurs, pas un serveur entierement compromis. Pour aller plus loin, chiffrer avec
  une cle publique dont la cle privee ne quitte jamais un poste de confiance.
- Les sauvegardes non chiffrees deja presentes restent lisibles : les supprimer une fois
  les chiffrees verifiees. Les *instantanes* (snapshots) du VPS, s'ils sont actives
  chez l'hebergeur, contiennent la base en clair.

### 2. Double authentification des administrateurs

**Non faite.** C'est la protection qui compte le plus contre un mot de passe vole ou
reutilise : sans elle, la securite de toutes les donnees tient a un seul mot de passe
par administrateur. A faire en priorite apres les sauvegardes (TOTP, avec codes de
secours ; le secret de chaque administrateur chiffre en base).

### 3. Durcir le serveur

A verifier sur le VPS — rien de tout cela ne se voit depuis le depot :

- connexion SSH par cle uniquement, `PermitRootLogin no`, mots de passe SSH desactives ;
- pare-feu ouvert sur 22, 80 et 443 seulement (`ufw`), `fail2ban` sur SSH ;
- mises a jour de securite automatiques (`unattended-upgrades`) ;
- un utilisateur de deploiement dedie, membre du groupe `docker` — pas `root` ;
- disque chiffre si l'hebergeur le propose.

### 4. Revoir les acces

- Tous les comptes de gestion — contributeurs compris — voient les donnees des
  adherents. Les revoir a chaque changement de bureau, **bloquer** (et non seulement
  oublier) le compte de qui part.
- Ne creer un compte que pour qui en a l'usage.

### 5. Appliquer la duree de conservation

La page « Donnees personnelles » promet une anonymisation trois ans apres la derniere
adhesion. **Elle est manuelle** : rien ne la declenche. Au minimum, la faire une fois par
saison (liste des adhesions, filtre par saison, « Anonymiser la selection »). L'automatiser
est possible — c'est une decision a prendre, car elle efface de facon irreversible.

### 6. Les fichiers qui sortent du site

Un export CSV, un fichier d'import, une fiche d'emargement : ce sont des donnees
personnelles hors du site. Ne pas les laisser dans « Telechargements », ne pas les
envoyer par courriel ou messagerie non chiffree, les supprimer apres usage. Chaque export
est journalise ; ce qu'on en fait ensuite ne l'est pas.

### 7. Savoir quoi faire en cas de fuite

1. Couper l'acces concerne (bloquer le compte, changer le secret).
2. Noter ce qui a fuite, depuis quand, pour combien de personnes.
3. **Prevenir la CNIL sous 72 heures** (notification de violation sur cnil.fr) si les
   personnes sont exposees a un risque, et les personnes elles-memes si le risque est
   eleve — d'ou l'interet d'avoir leurs adresses.
4. Consigner l'incident et ce qui a ete corrige.

## Ce qu'on n'a pas fait, et pourquoi

**Chiffrer les champs en base (adresse, telephone…).** La cle doit etre lue par
l'application, donc elle se trouve sur le meme serveur que la base : un attaquant qui
prend le serveur prend les deux. Le chiffrement de champs ne protege que d'une copie de
la base seule — ce que le chiffrement des sauvegardes fait deja pour moins cher. Il
casserait aussi la recherche par nom et par adresse. A reconsiderer si l'hebergement
change (base geree, hors du serveur de l'application).
