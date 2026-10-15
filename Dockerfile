# MedConnect PHP Web Service — Production Dockerfile for Railway / Container Environments
FROM php:8.2-apache

ENV DEBIAN_FRONTEND=noninteractive

# Install OS libraries required for PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libicu-dev \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mysqli \
        gd \
        zip \
        intl \
        opcache \
        bcmath \
    && a2enmod rewrite headers remoteip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer from official Composer image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory to standard Apache document root
WORKDIR /var/www/html

# Copy application source code
COPY . /var/www/html

# Install Composer dependencies (production only, optimized)
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN if [ -f composer.json ]; then \
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress; \
    fi

# Copy Apache and PHP configurations
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/medconnect.ini /usr/local/etc/php/conf.d/medconnect.ini

# Set up entrypoint script
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Initialize storage directories with Apache www-data ownership
RUN mkdir -p /var/www/html/storage/logs \
             /var/www/html/storage/cache \
             /var/www/html/storage/temp \
             /var/www/html/storage/uploads/ids \
             /var/www/html/storage/uploads/profile_pictures \
             /var/www/html/storage/uploads/announcements \
             /var/www/html/storage/uploads/media_library \
             /var/www/html/storage/recordings \
             /var/www/html/storage/ratelimit \
             /var/www/html/storage/residency \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod -R 775 /var/www/html/storage

# Default port for Railway (Railway overrides this with $PORT dynamically)
ENV PORT=80
EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
