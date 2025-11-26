FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    cron \
    && docker-php-ext-install \
    pdo \
    pdo_pgsql \
    intl \
    zip \
    opcache \
    simplexml \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configure PHP
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY ./custom.ini $PHP_INI_DIR/conf.d/custom.ini

# Configure PHP-FPM pool
COPY ./php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-custom.conf

# Set working directory
WORKDIR /var/www/html

# Copy crontab file
COPY ./crontab /etc/cron.d/prestaboost-cron
RUN chmod 0644 /etc/cron.d/prestaboost-cron \
    && crontab /etc/cron.d/prestaboost-cron \
    && touch /var/log/cron.log

# Copy entrypoint script
COPY ./entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
