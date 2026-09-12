# Politique de securite

## Versions suivies

Seule la version en production (branche `main`, derniere etiquette `v*`) recoit
des correctifs de securite. Le site n'a pas de version historique maintenue.

## Signaler une faille

**N'ouvrez pas d'issue publique pour une faille de securite** : une issue est
visible de tous, y compris avant le correctif.

Utilisez l'onglet **Security → Report a vulnerability** du depot (avis de
securite prive GitHub), qui ouvre un canal visible des seuls mainteneurs.

Merci d'y indiquer :

- la page ou le point d'entree concerne (URL, formulaire, parametre) ;
- ce qu'il faut faire pour reproduire ;
- l'effet obtenu (lecture de donnees d'autrui, execution de code, contournement
  d'authentification…).

Nous accusons reception sous **7 jours** et tenons le signalement informe
jusqu'au correctif. Ce depot est celui d'un site associatif tenu benevolement :
il n'y a ni prime ni delai contractuel.

## Perimetre

Dans le perimetre : le code de ce depot (`app/`, `docker/`, fichiers Compose et
workflows).

Hors perimetre : l'hebergement et le proxy de l'hote, les services tiers, et
tout test qui degraderait le service (deni de service, envoi massif, injection
de donnees dans les formulaires publics du site en production).

## Ce que le depot fait deja

- Les secrets ne sont jamais committes : `.env.*.local` sont exclus par
  `.gitignore`, les valeurs reelles vivent dans les secrets GitHub ou sur le
  serveur.
- `composer audit --locked` est une porte bloquante de la CI : une dependance
  portant une faille connue n'atteint pas `main`.
- Dependabot surveille Composer, npm, Docker et les actions GitHub.
