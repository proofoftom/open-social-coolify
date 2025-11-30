FROM php:8.3-apache

# Install system dependencies (including libraries for GD with WebP support)
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    libicu-dev \
    g++ \
    zip \
    unzip \
    netcat-traditional \
    postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# Configure GD with WebP, JPEG, and Freetype support (required by Open Social 11.5+)
RUN docker-php-ext-configure gd \
    --with-jpeg \
    --with-webp \
    --with-freetype

# Configure and install intl extension
RUN docker-php-ext-configure intl

# Install all PHP extensions required by Drupal and Open Social
RUN docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_pgsql \
    pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    opcache \
    intl

# Enable Apache modules
RUN a2enmod rewrite headers expires

# Set Apache document root
ENV APACHE_DOCUMENT_ROOT /var/www/html/html/web
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Configure PHP
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Create PHP configuration for Drupal
RUN { \
    echo 'memory_limit = 512M'; \
    echo 'upload_max_filesize = 64M'; \
    echo 'post_max_size = 64M'; \
    echo 'max_execution_time = 300'; \
    echo 'max_input_vars = 5000'; \
    echo 'realpath_cache_size = 4096k'; \
    echo 'realpath_cache_ttl = 600'; \
} > /usr/local/etc/php/conf.d/drupal.ini

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Create composer.json for Open Social with patching enabled
RUN cat > composer.json << 'COMPOSER_JSON'
{
    "name": "goalgorilla/open-social-project",
    "type": "project",
    "require": {
        "goalgorilla/open_social": "13.0.0-beta2",
        "drush/drush": "^13",
        "cweagans/composer-patches": "^1.7"
    },
    "extra": {
        "drupal-scaffold": {
            "locations": {
                "web-root": "html/web"
            }
        },
        "installer-paths": {
            "html/web/core": ["type:drupal-core"],
            "html/web/libraries/{$name}": ["type:drupal-library"],
            "html/web/modules/contrib/{$name}": ["type:drupal-module"],
            "html/web/profiles/contrib/{$name}": ["type:drupal-profile"],
            "html/web/themes/contrib/{$name}": ["type:drupal-theme"],
            "drush/Commands/contrib/{$name}": ["type:drupal-drush"]
        },
        "enable-patching": true,
        "composer-exit-on-patch-failure": true,
        "patchLevel": {
            "drupal/core": "-p2"
        }
    },
    "minimum-stability": "beta",
    "prefer-stable": true,
    "config": {
        "allow-plugins": {
            "composer/installers": true,
            "drupal/core-composer-scaffold": true,
            "cweagans/composer-patches": true,
            "oomphinc/composer-installers-extender": true,
            "phpstan/extension-installer": true,
            "dealerdirect/phpcodesniffer-composer-installer": true
        },
        "sort-packages": true
    }
}
COMPOSER_JSON

# Install Open Social via Composer (verbose to show patch application)
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader -v

# Create files directories
RUN mkdir -p html/web/sites/default/files \
    && mkdir -p /var/www/private \
    && chown -R www-data:www-data html/web/sites/default/files \
    && chown -R www-data:www-data /var/www/private \
    && chmod -R 755 html/web/sites/default/files

# Copy settings.php template
RUN cp html/web/sites/default/default.settings.php html/web/sites/default/settings.php \
    && chown www-data:www-data html/web/sites/default/settings.php \
    && chmod 644 html/web/sites/default/settings.php

# Copy entrypoint script
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
