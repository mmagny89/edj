# Configuration GitHub — edj

Tout ce qui se regle **dans l'interface GitHub** pour que ce depot
construise et deploie. A faire une fois, dans cet ordre. Le reste de la
mise en service — clones, secrets du serveur, Traefik, sauvegardes — est
dans `README.docker.md`.

Les cases a cocher de la fin resument les etapes : les reporter dans une
issue au moment de la mise en service evite d'en oublier une.

## 1. Le depot et ses deux branches

Le dispositif suppose exactement deux branches longues, et le workflow ne
reconnait que ces noms :

| Branche | Environnement |
|---|---|
| `main` | production |
| `develop` | pre-production (PPD) |

```sh
git push -u origin main
git switch -c develop && git push -u origin develop
```

Puis **Settings → General → Default branch** : garder `main`.

Le travail se fait sur des branches `feat/*` ou `fix/*`, fusionnees dans
`develop` puis dans `main`. Pousser directement sur `main` deploie en
production sans relecture : c'est ce que la section reseau/ports cherche a empecher.

## 2. Acces du serveur au depot

Si le depot est prive, un `git clone` depuis le serveur echoue tant
qu'aucune cle n'y est autorisee. Deux montages possibles, et le choix se
fait une fois pour toutes les machines :

| Montage | Ou | Quand |
|---|---|---|
| **Compte machine** invite en lecture | organisation → invitation, ou depot → Settings → Collaborators | plusieurs projets sur le meme serveur — une cle pour toutes |
| **Deploy key** en lecture seule | Settings → Deploy keys → Add deploy key | un seul projet, ou aucun compte machine disponible |

```sh
sh outils/installer-deploiement.sh serveur    # cree la cle et l'affiche
```

**« Allow write access » reste decoche.** Un deploiement ne pousse jamais.

Une deploy key etant refusee si deja utilisee sur un autre depot, un
serveur qui heberge plusieurs projets passe necessairement par le compte
machine.

## 3. Secrets et variables du deploiement

```sh
sh outils/installer-deploiement.sh projet prod       # depuis le clone de prod
sh outils/installer-deploiement.sh projet staging    # depuis le clone de PPD
```

Le script affiche exactement ce qu'il faut coller. **Settings → Secrets
and variables → Actions**, onglet **Secrets** :

| Production | Pre-production | Contenu |
|---|---|---|
| `DEPLOIEMENT_HOTE` | `STAGING_DEPLOIEMENT_HOTE` | adresse SSH du serveur |
| `DEPLOIEMENT_UTILISATEUR` | `STAGING_DEPLOIEMENT_UTILISATEUR` | utilisateur de deploiement |
| `DEPLOIEMENT_CLE_PRIVEE` | `STAGING_DEPLOIEMENT_CLE_PRIVEE` | cle privee dont la publique est dans `authorized_keys` |
| `DEPLOIEMENT_KNOWN_HOSTS` | `STAGING_DEPLOIEMENT_KNOWN_HOSTS` | empreinte du serveur, epinglee |

Puis l'onglet **Variables** — c'est l'**interrupteur** :

| Variable | Valeur | Effet |
|---|---|---|
| `DEPLOIEMENT_PROD` | `oui` | un push sur `main` deploie la production |
| `DEPLOIEMENT_STAGING` | `oui` | un push sur `develop` deploie la PPD |

Tant qu'une variable est absente ou differente de `oui`, son job de
deploiement est **ignore** : le depot construit et verifie, il ne livre
rien. C'est le defaut, et c'est voulu — un depot sans serveur garde le
meme workflow, et suspendre un deploiement ne demande pas de toucher aux
secrets.

Les deux jeux de secrets **ne sont pas interchangeables** : chaque cle est
figee sur son environnement par la *forced command* de l'hote.

## 4. Reglages Actions

**Settings → Actions → General** :

| Reglage | Valeur | Pourquoi |
|---|---|---|
| Fork pull request workflows from outside collaborators | **Require approval for all external contributors** | sans quoi une PR d'un inconnu execute du code dans votre CI |
| Workflow permissions | **Read repository contents and packages permissions** | le `GITHUB_TOKEN` n'a aucune raison d'ecrire ici : rien ne commite, ne tague ni ne publie |
| Allow GitHub Actions to create and approve pull requests | **decoche** | une approbation automatique viderait de son sens la relecture |

Le deploiement, lui, ne passe pas par le `GITHUB_TOKEN` : il sort en SSH
avec une cle restreinte a un seul script. Restreindre le jeton ne le gene
donc en rien.

## 5. Protection des branches

Le workflow expose un job agregateur, `Portes qualité`, qui echoue si
l'une des portes cede. **C'est le seul controle a exiger** : ajouter ou
renommer une porte ne casse alors aucune regle.

**Settings → Rules → Rulesets → New branch ruleset**, cible `main` et
`develop` :

- Require a pull request before merging — 1 approbation, ou 0 si vous
  travaillez seul : la PR reste utile pour lire son propre diff ;
- Require status checks to pass → `Portes qualité` ;
- Block force pushes.

**Sur un compte gratuit, ces regles ne s'appliquent qu'aux depots
publics.** Un depot prive sur l'offre gratuite ne peut ni les rulesets ni
les branch protection rules : la CI tourne et affiche son verdict, mais
rien n'empeche techniquement de fusionner au rouge. Deux consequences a
assumer :

- la discipline remplace la regle — on ouvre quand meme une PR, on attend
  quand meme le vert ;
- l'interrupteur de la section env/trois-niveaux devient la seule barriere reelle devant
  la production. Garder `DEPLOIEMENT_PROD` eteint tant que le projet n'est
  pas en service est un geste qui coute une seconde.

## 6. Securite du depot

**Settings → Advanced Security** (ou **Code security**) :

| Reglage | Depot prive, offre gratuite |
|---|---|
| Dependabot alerts | **disponible, a activer** |
| Dependabot security updates | **disponible, a activer** — corrections proposees en PR |
| Secret scanning + push protection | reserve aux depots publics ou a une offre payante |
| Private vulnerability reporting | depots publics |

Activer les deux premieres lignes : la porte `Audit des dependances` du
workflow ne regarde que `composer.lock` au moment du push, tandis que
Dependabot signale une vulnerabilite publiee **apres** le dernier commit —
le cas le plus courant sur un projet qui vit.

Ce que GitHub ne doit **jamais** voir, quelle que soit l'offre : les
secrets applicatifs (`APP_SECRET`, mots de passe de base, DSN de
messagerie). Ils vivent dans `.env.<env>.local` **sur le serveur**, hors
du depot. Les quatre secrets de la section env/trois-niveaux ne sont pas des secrets
applicatifs : ce sont des cles d'acces au serveur, et ils n'ouvrent qu'un
script.

## 7. A verifier, dans cet ordre

```sh
git switch develop && git commit --allow-empty -m "chore: eprouver la CI" && git push
```

1. **Onglet Actions** : les portes tournent. Une porte ignoree ecrit une
   ligne dans le resume du run, nommant l'outil manquant — c'est normal
   sur un projet neuf.
2. **Resume du run** : `Portes qualité` est vert.
3. **`Déploiement — staging`** : ignore si la variable manque, execute
   sinon. S'il echoue sur « Secrets manquants », la section env/trois-niveaux est
   incomplete.
4. Depuis l'exterieur, et pas depuis le conteneur :
   ```sh
   curl -sI https://ppd.<domaine>/ | head -3
   ```

## Recapitulatif

- [ ] `main` et `develop` poussees, `main` par defaut
- [ ] acces du serveur au depot : compte machine invite, ou deploy key en lecture seule
- [ ] quatre secrets de production
- [ ] quatre secrets de pre-production, prefixes `STAGING_`
- [ ] `DEPLOIEMENT_PROD` et `DEPLOIEMENT_STAGING` posees a `oui` — ou laissees absentes sciemment
- [ ] PR de forks : approbation requise
- [ ] `GITHUB_TOKEN` en lecture seule
- [ ] ruleset sur `main` et `develop`, controle requis `Portes qualité` (depot public ou offre payante)
- [ ] Dependabot : alertes et mises a jour de securite
- [ ] un push sur `develop` passe les portes
- [ ] le site de PPD repond depuis l'exterieur
