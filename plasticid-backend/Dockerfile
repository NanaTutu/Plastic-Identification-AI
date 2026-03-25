FROM php:8.3-apache

# Enable Apache rewrite
RUN a2enmod rewrite

# Install system packages needed for intl
RUN apt-get update && apt-get install -y \
    libicu-dev \
    && docker-php-ext-install intl mysqli pdo pdo_mysql

# Copy Apache config
COPY apache.conf /etc/apache2/sites-available/000-default.conf

# Copy CodeIgniter app
COPY . /var/www/html

# Fix permissions
RUN chown -R www-data:www-data /var/www/html/writable \
 && chmod -R 775 /var/www/html/writable

WORKDIR /var/www/html
EXPOSE 80
