# Envie de Jouer — Site de l'association

Application web de l'association **Envie de Jouer**, construite avec Symfony 8, FrankenPHP, PostgreSQL 16 et Webpack Encore.

## Stack technique

| Composant | Version |
|---|---|
| PHP | 8.4 (via FrankenPHP) |
| Symfony | 8.0 |
| PostgreSQL | 16 |
| FrankenPHP / Caddy | 1.x |
| Node.js | 24 |

## Structure du projet

```
/
├── docker/               ← infrastructure Docker
│   ├── Dockerfile
│   └── frankenphp/
│       ├── Caddyfile
│       ├── docker-entrypoint.sh
│       └── conf.d/
├── symfony/              ← application Symfony
│   ├── assets/
│   ├── bin/
│   ├── config/
│   ├── migrations/
│   ├── public/
│   ├── src/
│   ├── templates/
│   └── ...
├── compose.yaml          ← dev
├── compose.prod.yaml     ← prod
└── README.md
```

---

## Installation en local

### Prérequis

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (v2.10+)
- [Node.js 24](https://nodejs.org/) et npm
- Git

### 1. Cloner le dépôt

```bash
git clone <url-du-repo>
cd edj
```

### 2. Créer le fichier de configuration locale

```bash
cp symfony/.env.local.example symfony/.env.local
```

Éditer `symfony/.env.local` :

```dotenv
APP_ENV=dev
APP_SECRET=une_chaine_aleatoire_32_caracteres

POSTGRES_DB=edj
POSTGRES_USER=root
POSTGRES_PASSWORD=ton_mot_de_passe
POSTGRES_VERSION=16

DATABASE_URL="postgresql://${POSTGRES_USER}:${POSTGRES_PASSWORD}@database:5432/${POSTGRES_DB}?serverVersion=${POSTGRES_VERSION}&charset=utf8"

MERCURE_PUBLISHER_JWT_KEY=une_cle_secrete_mercure
MERCURE_SUBSCRIBER_JWT_KEY=une_cle_secrete_mercure
MERCURE_JWT_SECRET=une_cle_secrete_mercure
```

> Il suffit de renseigner les variables `POSTGRES_*` — `DATABASE_URL` se construit automatiquement.

> `symfony/.env.local` n'est jamais commité — il contient tous les secrets.

### 3. Démarrer les conteneurs Docker

```bash
docker compose up -d --build
```

### 4. Appliquer les migrations

```bash
docker exec -it edj-php-1 php bin/console doctrine:migrations:migrate
```

### 5. Installer les dépendances Node et compiler les assets

```bash
cd symfony
npm install
npm run dev
```

Pour recompiler automatiquement à chaque modification :

```bash
npm run watch
```

### 6. Accéder à l'application

Ouvrir [https://localhost](https://localhost) et accepter le certificat TLS auto-signé.

---

## Commandes du quotidien

Toutes les commandes Symfony s'exécutent dans le conteneur Docker depuis `/app` (= `symfony/`) :

```bash
# Démarrer les conteneurs
docker compose up -d

# Arrêter les conteneurs
docker compose down

# Voir les logs
docker compose logs -f php

# Accéder au shell du conteneur PHP
docker exec -it edj-php-1 bash

# Vider le cache Symfony
docker exec -it edj-php-1 php bin/console cache:clear

# Créer une migration après modification d'une entité
docker exec -it edj-php-1 php bin/console make:migration

# Appliquer les migrations
docker exec -it edj-php-1 php bin/console doctrine:migrations:migrate
```

Pour les commandes Node, se placer dans `symfony/` :

```bash
cd symfony
npm run dev      # compilation unique
npm run watch    # recompilation automatique
npm run build    # build de production
```

---

## Build de production

### 1. Préparer `symfony/.env.local` sur le serveur

```dotenv
APP_ENV=prod
APP_SECRET=une_chaine_vraiment_aleatoire

POSTGRES_DB=edj
POSTGRES_USER=prod_user
POSTGRES_PASSWORD=mot_de_passe_fort
POSTGRES_VERSION=16

DATABASE_URL="postgresql://${POSTGRES_USER}:${POSTGRES_PASSWORD}@database:5432/${POSTGRES_DB}?serverVersion=${POSTGRES_VERSION}&charset=utf8"

MERCURE_PUBLISHER_JWT_KEY=cle_secrete_prod
MERCURE_SUBSCRIBER_JWT_KEY=cle_secrete_prod
MERCURE_JWT_SECRET=cle_secrete_prod
```

### 2. Compiler les assets

```bash
cd symfony && npm run build
```

### 3. Démarrer la stack de production

```bash
docker compose -f compose.prod.yaml up -d --build
```

### 4. Appliquer les migrations

```bash
docker exec -it edj-php-1 php bin/console doctrine:migrations:migrate --no-interaction
```

---

## Mise à jour de la stack

### Symfony et packages PHP

```bash
docker exec -it edj-php-1 composer update
docker exec -it edj-php-1 php bin/console cache:clear
```

### PHP / FrankenPHP

Dans `docker/Dockerfile`, modifier :

```dockerfile
FROM dunglas/frankenphp:1-php8.4 AS frankenphp_upstream
```

Puis rebuilder :

```bash
docker compose down && docker compose up -d --build
```

### PostgreSQL

Dans `compose.yaml` et `compose.prod.yaml`, modifier :

```yaml
image: postgres:16-alpine
```

> **Attention** : montée de version majeure → dump avant (`pg_dump`), drop du volume (`-v`), restore.

### Packages Node

```bash
cd symfony
npm outdated
npm update
npm run dev
```
