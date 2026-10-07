FROM php:8.3-apache

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    git \
    libicu-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-install intl mysqli pdo pdo_mysql \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/* /tmp/composer

RUN a2enmod rewrite

COPY composer.json composer.lock ./
# Build with `--build-arg COMPOSER_FLAGS=` to include dev dependencies (phpunit)
# for a one-off test run.
ARG COMPOSER_FLAGS=--no-dev
RUN composer install \
    ${COMPOSER_FLAGS} \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

COPY apache.conf /etc/apache2/sites-available/000-default.conf
COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html/writable \
    && chmod -R 775 /var/www/html/writable

WORKDIR /var/www/html
EXPOSE 80

CMD ["sh", "-c", "php spark migrate --force && exec apache2-foreground"]
