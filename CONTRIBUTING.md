# Contribuer

Le depot est public pour que le travail de l'association soit lisible et
reutilisable. Les contributions exterieures sont les bienvenues, en gardant en
tete que les decisions fonctionnelles appartiennent a l'association.

## Avant d'ecrire du code

Ouvrez une issue (gabarits « Bogue » ou « Evolution ») : une discussion de dix
lignes evite une demande de fusion refusee.

## Mettre en route le projet

Tout est dans [README.docker.md](README.docker.md) — un `docker compose up -d
--wait` suffit, l'application vit dans `app/`.

## Branches et commits

- Une branche par sujet, depuis `main` : `feat/<sujet>` ou `fix/<sujet>`.
- Messages de commit au format [Conventional Commits](https://www.conventionalcommits.org/fr/v1.0.0/) :
  `feat(evenements): afficher les dates a venir`, `fix(accueil): ...`.
  Les types utilises ici : `feat`, `fix`, `docs`, `refactor`, `test`, `chore`,
  `ci`, `perf`, `style`.
- Un commit par changement coherent, redige en francais sans accent dans le
  sujet, et sans attribution a un outil.

## Ce que la CI verifie

Le workflow `qualite.yml` doit passer avant toute fusion :

- `composer validate`, `composer audit --locked` (faille connue = blocage) ;
- lint PHP, lint du conteneur et de la configuration, lint Twig ;
- PHPUnit ;
- build du front (Webpack Encore) et de l'image de production.

Lancez-les en local plutot que de decouvrir l'echec dans la demande de fusion :

```sh
docker compose exec php composer audit --locked
docker compose exec php php bin/console lint:twig templates
docker compose exec php vendor/bin/phpunit
```

## Style de code

Symfony Coding Standards (PSR-12) cote PHP, Tailwind v4 cote gabarits. Pas de
reformatage massif melange a un changement fonctionnel : il rend la relecture
impossible.

## Licence

En contribuant, vous acceptez que votre contribution soit publiee sous
[AGPL-3.0-or-later](LICENSE), comme le reste du depot.
