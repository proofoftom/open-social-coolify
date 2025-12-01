FROM php:8.3-apache

# Install system dependencies (including libraries for GD with WebP support and GMP for SIWE)
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
    default-libmysqlclient-dev \
    libicu-dev \
    libgmp-dev \
    g++ \
    zip \
    unzip \
    netcat-traditional \
    mariadb-client \
    openssl \
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
    pdo_mysql \
    mysqli \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    gmp \
    zip \
    opcache \
    intl

# Enable Apache modules
RUN a2enmod rewrite headers expires

# Set Apache document root
ENV APACHE_DOCUMENT_ROOT /var/www/html/html
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

# Copy composer.json for Open Social with patching enabled
COPY composer.json .

# Install Open Social via Composer (verbose to show patch application)
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader -v

# Create files directories and ensure sites/default is writable
RUN mkdir -p html/sites/default/files \
    && mkdir -p /var/www/private \
    && chown -R www-data:www-data html/sites/default/files \
    && chown -R www-data:www-data /var/www/private \
    && chown -R www-data:www-data html/sites/default \
    && chmod -R 755 html/sites/default/files \
    && chmod 755 html/sites/default

# Copy entrypoint script
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
