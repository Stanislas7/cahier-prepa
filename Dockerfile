FROM php:8.2-apache

# System deps commonly needed by PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libzip-dev libicu-dev git unzip \
  && rm -rf /var/lib/apt/lists/*

# PHP extensions: mysqli/pdo_mysql (DB), gd (images), zip, intl, mbstring, opcache
RUN docker-php-ext-configure gd --with-jpeg \
  && docker-php-ext-install -j$(nproc) mysqli pdo_mysql gd zip intl mbstring opcache

# Enable Apache modules often needed by PHP apps
RUN a2enmod rewrite headers expires

# Set recommended PHP limits (tweak as you like)
COPY ./php.ini /usr/local/etc/php/conf.d/custom.ini

# App code goes under /var/www/html (will be bind-mounted by compose)
WORKDIR /var/www/html
