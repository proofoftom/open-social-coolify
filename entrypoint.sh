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

// Trusted host patterns - auto-detect from Coolify or use environment variable
$trusted_patterns = [];

// Check for Coolify-provided FQDN/URL variables
foreach (['SERVICE_FQDN_OPENSOCIAL', 'SERVICE_URL_OPENSOCIAL', 'COOLIFY_FQDN', 'COOLIFY_URL'] as $env_var) {
  if ($value = getenv($env_var)) {
    // Handle comma-separated values (Coolify sometimes provides multiple)
    foreach (explode(',', $value) as $url) {
      $url = trim($url);
      if (empty($url)) continue;
      // Extract hostname from URL if needed
      $host = parse_url($url, PHP_URL_HOST) ?: preg_replace('/^https?:\/\//', '', $url);
      $host = strtolower(trim($host));
      if (!empty($host)) {
        $trusted_patterns[] = '^' . preg_quote($host, '/') . '$';
      }
    }
  }
}

// Also check manual trusted host patterns from environment
if ($manual_hosts = getenv('DRUPAL_TRUSTED_HOST_PATTERNS')) {
  $trusted_patterns = array_merge($trusted_patterns, array_filter(array_map('trim', explode(',', $manual_hosts))));
}

// Always allow localhost for health checks
$trusted_patterns[] = '^localhost$';
$trusted_patterns[] = '^127\.0\.0\.1$';

// Remove duplicates and set
$settings['trusted_host_patterns'] = array_values(array_unique(array_filter($trusted_patterns)));

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
