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

# Copy composer.json for Open Social 13.0.0-beta2
COPY composer.json .

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
