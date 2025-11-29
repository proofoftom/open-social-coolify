#!/bin/bash
set -e

SETTINGS_FILE="/var/www/html/html/sites/default/settings.php"

# Wait for database to be ready
if [ -n "$DB_HOST" ]; then
    echo "Waiting for database at $DB_HOST:${DB_PORT:-5432}..."
    timeout=60
    while ! nc -z "$DB_HOST" "${DB_PORT:-5432}" 2>/dev/null; do
        timeout=$((timeout - 1))
        if [ $timeout -le 0 ]; then
            echo "Database connection timeout!"
            exit 1
        fi
        sleep 1
    done
    echo "Database is ready!"
fi

# Append database configuration if not already present
if ! grep -q "DATABASE SETTINGS FROM ENVIRONMENT" "$SETTINGS_FILE"; then
    cat >> "$SETTINGS_FILE" << 'EOPHP'

// DATABASE SETTINGS FROM ENVIRONMENT
$databases['default']['default'] = [
  'database' => getenv('DB_NAME') ?: 'opensocial',
  'username' => getenv('DB_USER') ?: 'opensocial',
  'password' => getenv('DB_PASSWORD') ?: 'opensocial',
  'host' => getenv('DB_HOST') ?: 'postgres',
  'port' => getenv('DB_PORT') ?: '5432',
  'driver' => 'pgsql',
  'prefix' => '',
];

// Private file path
$settings['file_private_path'] = '/var/www/private';

// Trusted host patterns from environment
if ($trusted_hosts = getenv('DRUPAL_TRUSTED_HOST_PATTERNS')) {
  $settings['trusted_host_patterns'] = array_filter(array_map('trim', explode(',', $trusted_hosts)));
}

// Hash salt from environment or generate one
$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT') ?: hash('sha256', 'change-this-hash-salt-in-production');

// Config sync directory
$settings['config_sync_directory'] = '../config/sync';

// Reverse proxy settings for Coolify/Traefik
if (getenv('DRUPAL_REVERSE_PROXY')) {
  $settings['reverse_proxy'] = TRUE;
  $settings['reverse_proxy_addresses'] = ['127.0.0.1', '172.16.0.0/12', '192.168.0.0/16', '10.0.0.0/8'];
  $settings['reverse_proxy_trusted_headers'] = \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_HOST | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PORT | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO;
}
EOPHP
fi

# Ensure proper permissions
chown -R www-data:www-data /var/www/html/html/sites/default/files 2>/dev/null || true
chown -R www-data:www-data /var/www/private 2>/dev/null || true
chmod 775 /var/www/private

exec "$@"
