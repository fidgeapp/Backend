FROM php:8.4-apache

# Set environment variables
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
# This allows Composer to run as root without warnings/errors in the build log
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# Configure Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    libgmp-dev \
    zip \
    && docker-php-ext-install pdo pdo_mysql mbstring zip exif pcntl gmp

# Enable Apache rewrite module
RUN a2enmod rewrite

# Get the latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application files
COPY . .

# Create required Laravel directories and set initial permissions
# We do this before composer install to ensure the environment is ready
RUN mkdir -p bootstrap/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache \
    storage/logs \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

# Install PHP dependencies
# This uses your synced composer.lock (ensure you ran 'composer update' locally first)
RUN rm -rf vendor && composer install --no-dev --optimize-autoloader --no-interaction

# Configure Apache VirtualHost
RUN echo "<VirtualHost *:80>\n\
    DocumentRoot ${APACHE_DOCUMENT_ROOT}\n\
    <Directory ${APACHE_DOCUMENT_ROOT}>\n\
        Options Indexes FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    ErrorLog \${APACHE_LOG_DIR}/error.log\n\
    CustomLog \${APACHE_LOG_DIR}/access.log combined\n\
</VirtualHost>" > /etc/apache2/sites-available/000-default.conf

# Update Apache config to use the new document root
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Prepare entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]