# ConciliaRH — imagen PHP SOLO PARA DESARROLLO LOCAL.
# Producción NO usa Docker (ver docs/DEPLOYMENT.md).
FROM php:8.3-apache

ARG UID=1000
ARG GID=1000

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev unzip git \
 && docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install pdo_pgsql intl bcmath opcache zip gd pcntl \
 && apt-get purge -y --auto-remove \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache: módulos, vhost y endurecimiento básico
RUN a2enmod rewrite headers \
 && a2dissite 000-default \
 && sed -ri 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf
COPY infra/docker/apache/conciliarh.conf /etc/apache2/sites-available/conciliarh.conf
COPY infra/docker/php/conciliarh.ini /usr/local/etc/php/conf.d/zz-conciliarh.ini
RUN a2ensite conciliarh

# Alinear UID/GID de www-data con el usuario del host para el volumen montado
RUN groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g ${GID} www-data

WORKDIR /var/www/html
