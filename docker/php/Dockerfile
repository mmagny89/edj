# syntax=docker/dockerfile:1
#
# Stages: base, dev, prod_builder, prod, staging (nommage normatif, section 2
# des conventions). Aucune valeur par defaut sur les ARG alimentes par
# compose : voir section 5 des conventions (InvalidDefaultArgInFrom assume).

ARG PHP_VERSION
ARG FRANKENPHP_VERSION
ARG PHP_EXTENSION_INSTALLER_VERSION
ARG NODE_VERSION

# Stage intermediaire pour rendre PHP_EXTENSION_INSTALLER_VERSION utilisable
# dans un COPY --from= : la variable expansion n'est pas supportee
# directement dans la reference d'image d'un --from (limitation BuildKit).
FROM mlocati/php-extension-installer:${PHP_EXTENSION_INSTALLER_VERSION} AS php_extension_installer

# Meme contournement que ci-dessus pour NODE_VERSION dans un COPY --from=.
FROM node:${NODE_VERSION}-bookworm-slim AS node_upstream

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-bookworm AS base

WORKDIR /app

# L'image FrankenPHP livre une application de demonstration dans /app/public.
# La laisser en place fausserait la detection "application pas encore
# installee" (bascule sur Caddyfile.bootstrap) et provoquerait une boucle de
# crash du worker une fois en production.
RUN rm -rf /app/public /app/*

RUN apt-get update && apt-get install --no-install-recommends -y \
        acl \
        libcap2-bin \
    && rm -rf /var/lib/apt/lists/*

COPY --from=php_extension_installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
        @composer \
        apcu \
        intl \
        opcache \
        pdo_pgsql \
        zip

COPY docker/php/conf.d/10-app.ini /usr/local/etc/php/conf.d/10-app.ini
COPY docker/php/Caddyfile /etc/caddy/Caddyfile.nominal
COPY docker/php/Caddyfile.bootstrap /etc/caddy/Caddyfile.bootstrap
COPY docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY docker/php/docker-healthcheck.sh /usr/local/bin/docker-healthcheck
RUN chmod +x /usr/local/bin/docker-entrypoint /usr/local/bin/docker-healthcheck

ENTRYPOINT ["docker-entrypoint"]
HEALTHCHECK --interval=10s --timeout=3s --start-period=30s --retries=5 CMD ["docker-healthcheck"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

# ---------------------------------------------------------------------------
# dev — Xdebug installe mais pilote uniquement par la variable
# d'environnement XDEBUG_MODE du conteneur (conventions, section 13) :
# jamais d'ENV XDEBUG_MODE= figee ici, sans quoi un changement de mode
# demanderait un rebuild au lieu d'un simple redemarrage.
FROM base AS dev

ARG USER_ID
ARG GROUP_ID

ENV APP_ENV=dev \
    FRANKENPHP_WORKER_CONFIG=watch \
    COMPOSER_ALLOW_SUPERUSER=1

RUN install-php-extensions xdebug

# Node est installe dans dev pour permettre "npm run watch" depuis le
# conteneur. Le front de ce projet est compile par Webpack Encore, pas par
# AssetMapper : ecart assume au gabarit .claude/resources/symfony-docker.
COPY --from=node_upstream /usr/local/bin/node /usr/local/bin/node
COPY --from=node_upstream /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

COPY docker/php/conf.d/20-app.dev.ini /usr/local/etc/php/conf.d/20-app.dev.ini
COPY docker/php/install-symfony.sh /usr/local/bin/install-symfony
COPY docker/php/neutralize-app-env.sh /usr/local/bin/neutralize-app-env
RUN chmod +x /usr/local/bin/install-symfony /usr/local/bin/neutralize-app-env

# La capability ne survit pas forcement a un COPY entre stages (piege connu) ;
# ici le binaire n'est jamais recopie depuis un autre stage, setcap s'applique
# donc directement au binaire livre par l'image de base.
RUN setcap 'cap_net_bind_service=+ep' "$(readlink -f "$(command -v frankenphp)")"

# Aligne l'utilisateur du conteneur sur l'UID/GID de l'hote (section 6) :
# sans effet sur macOS, indispensable sur Linux pour eviter un app/
# appartenant a root via le bind mount.
# -o : autorise un GID/UID deja utilise par un autre compte de l'image de
# base (frequent sur macOS, GID 20 = "staff") sans toucher a ce compte.
RUN groupmod -o -g "${GROUP_ID}" www-data \
    && usermod -o -u "${USER_ID}" -g "${GROUP_ID}" www-data \
    && mkdir -p /data /config /run/caddy \
    && chown -R www-data:www-data /data /config /run/caddy /etc/caddy /app

USER www-data

# ---------------------------------------------------------------------------
# prod_builder — installe les dependances applicatives sans etre livre.
FROM base AS prod_builder

ENV APP_ENV=prod \
    COMPOSER_ALLOW_SUPERUSER=1

# Tolerant a l'absence de composer.json/composer.lock : app/ est encore vide
# au premier build, avant que composer create-project ne soit lance.
COPY app/composer.jso[n] app/composer.loc[k] ./

RUN if [ -f composer.json ]; then \
        composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts; \
    fi

COPY app/ ./

RUN if [ -f composer.json ]; then \
        composer dump-autoload --no-dev --optimize --classmap-authoritative; \
    fi

# Compilation des assets, dans l'image et jamais au demarrage : le stage prod
# tourne en lecture seule sous l'utilisateur 1000, et l'image doit etre servie
# telle quelle.
#
# Ce projet compile son front avec Webpack Encore (webpack.config.js,
# public/build/), pas avec AssetMapper : le bloc tailwind:build /
# importmap:install / asset-map:compile du gabarit
# .claude/resources/symfony-docker/Dockerfile est remplace par npm ci +
# npm run build. public/build/ est ignore par git, donc absent d'un clone
# frais : sans cette etape, toute page levant un appel a encore_entry_*_tags()
# repondrait 500 en production.
#
# npm ci et non npm install : la version installee doit etre celle du
# package-lock.json, a l'identique d'un build a l'autre.
COPY --from=node_upstream /usr/local/bin/node /usr/local/bin/node
COPY --from=node_upstream /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

RUN if [ -f package-lock.json ]; then \
        npm ci --no-audit --no-fund; \
        npm run build; \
        rm -rf node_modules; \
    fi

# ---------------------------------------------------------------------------
# prod — worker actif, image rootless, opcache fige (conventions, section
# 13 : opcache.validate_timestamps = 0 dans conf.d/20-app.prod.ini).
FROM base AS prod

ENV APP_ENV=prod \
    FRANKENPHP_WORKER_CONFIG=""

COPY docker/php/conf.d/20-app.prod.ini /usr/local/etc/php/conf.d/20-app.prod.ini
COPY --from=prod_builder /app /app

# Meme remarque que pour dev : le binaire n'est pas recopie depuis un autre
# stage ici, donc le setcap s'applique correctement. A signaler malgre tout a
# stack-env-config : si ce stage venait a etre reconstruit un jour sur une
# base distincte avec COPY du binaire, la capability pourrait ne pas
# survivre, d'ou l'interet d'un sysctl net.ipv4.ip_unprivileged_port_start=0
# en defense en profondeur.
RUN setcap 'cap_net_bind_service=+ep' "$(readlink -f "$(command -v frankenphp)")" \
    && mkdir -p /data /config /run/caddy \
    && chown -R 1000:1000 /app /data /config /run/caddy /etc/caddy

USER 1000:1000

# ---------------------------------------------------------------------------
# staging — derive de prod. Logs verbeux, pas de Xdebug. conf.d/20-app.staging.ini
# redefinit explicitement opcache.validate_timestamps et opcache.revalidate_freq :
# sans ces deux lignes, ce stage heriterait en silence de l'opcache fige de
# prod, charge avant lui (conventions, section 13). Ce n'est jamais correct.
FROM prod AS staging

ENV APP_ENV=staging

COPY docker/php/conf.d/20-app.staging.ini /usr/local/etc/php/conf.d/20-app.staging.ini
