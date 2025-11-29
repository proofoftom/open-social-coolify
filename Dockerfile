FROM php:8.3-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    wget \
    curl \
    netcat-openbsd \
    libpq-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libwebp-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_pgsql gd zip opcache mbstring bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Create project directory
WORKDIR /var/www/html

# Create composer.json for Open Social 13.0.0-beta2
# Based on social_template but targeting the specific version
RUN cat > composer.json << 'EOF'
{
    "name": "goalgorilla/social_docker",
    "description": "Open Social 13.0.0-beta2 Docker build",
    "type": "project",
    "license": "GPL-2.0-or-later",
    "minimum-stability": "beta",
    "prefer-stable": true,
    "repositories": [
        {
            "type": "composer",
            "url": "https://packages.drupal.org/8"
        },
        {
            "type": "composer",
            "url": "https://asset-packagist.org"
        }
    ],
    "require": {
        "composer/installers": "^2.0",
        "cweagans/composer-patches": "^1.7",
        "drupal/core-composer-scaffold": "^10",
        "goalgorilla/open_social": "13.0.0-beta2",
        "oomphinc/composer-installers-extender": "^2.0",
        "drush/drush": "^12 || ^13"
    },
    "config": {
        "allow-plugins": {
            "composer/installers": true,
            "cweagans/composer-patches": true,
            "drupal/core-composer-scaffold": true,
            "oomphinc/composer-installers-extender": true,
            "php-http/discovery": true,
            "phpstan/extension-installer": true,
            "dealerdirect/phpcodesniffer-composer-installer": true
        },
        "sort-packages": true
    },
    "extra": {
        "drupal-scaffold": {
            "locations": {
                "web-root": "html/"
            }
        },
        "installer-types": [
            "bower-asset",
            "npm-asset"
        ],
        "installer-paths": {
            "html/core": ["type:drupal-core"],
            "html/libraries/{$name}": [
                "type:drupal-library",
                "type:bower-asset",
                "type:npm-asset"
            ],
            "html/modules/contrib/{$name}": ["type:drupal-module"],
            "html/profiles/contrib/{$name}": ["type:drupal-profile"],
            "html/themes/contrib/{$name}": ["type:drupal-theme"],
            "drush/Commands/contrib/{$name}": ["type:drupal-drush"]
        }
    }
}
EOF

# Install dependencies
RUN composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader

# Setup Apache
RUN a2enmod rewrite headers expires

# Apache configuration - DocumentRoot points to /var/www/html/html (Drupal webroot)
RUN sed -i 's!DocumentRoot /var/www/html!DocumentRoot /var/www/html/html!' /etc/apache2/sites-available/000-default.conf && \
    echo '<Directory /var/www/html/html>' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    Options -Indexes +FollowSymLinks' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    AllowOverride All' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    Require all granted' >> /etc/apache2/sites-available/000-default.conf && \
    echo '</Directory>' >> /etc/apache2/sites-available/000-default.conf

# Setup private files directory
RUN mkdir -p /var/www/private && \
    chmod 775 /var/www/private && \
    chown -R www-data:www-data /var/www/private

# Prepare settings.php from default
RUN cp /var/www/html/html/sites/default/default.settings.php /var/www/html/html/sites/default/settings.php && \
    chmod 666 /var/www/html/html/sites/default/settings.php && \
    mkdir -p /var/www/html/html/sites/default/files && \
    chmod 775 /var/www/html/html/sites/default/files

# Set ownership
RUN chown -R www-data:www-data /var/www/html

# PHP configuration for Drupal
RUN echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/drupal.ini && \
    echo "upload_max_filesize = 64M" >> /usr/local/etc/php/conf.d/drupal.ini && \
    echo "post_max_size = 64M" >> /usr/local/etc/php/conf.d/drupal.ini && \
    echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/drupal.ini && \
    echo "opcache.enable = 1" >> /usr/local/etc/php/conf.d/drupal.ini && \
    echo "opcache.memory_consumption = 256" >> /usr/local/etc/php/conf.d/drupal.ini

WORKDIR /var/www/html

# Entrypoint script for runtime configuration
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]
