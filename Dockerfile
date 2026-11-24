FROM php:8.4-apache

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install pdo_mysql \
 && a2enmod rewrite \
 && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Заглушка, пока нет собранного фронтенда
RUN mkdir -p /var/www/web/build \
 && echo '<h1>Форум: фронтенд ещё не собран</h1>' > /var/www/web/build/index.html
