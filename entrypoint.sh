#!/bin/bash
set -e

# Define paths
DRUPAL_ROOT="/var/www/html/html/web"
SETTINGS_FILE="$DRUPAL_ROOT/sites/default/settings.php"
DEFAULT_SETTINGS="$DRUPAL_ROOT/sites/default/default.settings.php"
FILES_DIR="$DRUPAL_ROOT/sites/default/files"
PRIVATE_DIR="/var/www/private"

# Wait for database
echo "Waiting for database at ${DB_HOST:-postgres}:${DB_PORT:-5432}..."
while ! nc -z "${DB_HOST:-postgres}" "${DB_PORT:-5432}"; do
    sleep 1
done
echo "Database is ready!"

# Create files directories if they don't exist
mkdir -p "$FILES_DIR"
mkdir -p "$PRIVATE_DIR"
chown -R www-data:www-data "$FILES_DIR"
chown -R www-data:www-data "$PRIVATE_DIR"
chmod -R 755 "$FILES_DIR"

# Create settings.php if it doesn't exist
if [ ! -f "$SETTINGS_FILE" ]; then
    echo "Creating settings.php..."
    if [ -f "$DEFAULT_SETTINGS" ]; then
        cp "$DEFAULT_SETTINGS" "$SETTINGS_FILE"
        chown www-data:www-data "$SETTINGS_FILE"
        chmod 644 "$SETTINGS_FILE"
    else
        echo "ERROR: default.settings.php not found at $DEFAULT_SETTINGS"
        exit 1
    fi
fi

# Generate hash salt if not provided
if [ -z "$DRUPAL_HASH_SALT" ]; then
    DRUPAL_HASH_SALT=$(openssl rand -hex 32)
    echo "Generated DRUPAL_HASH_SALT"
fi

# Build trusted host patterns
TRUSTED_HOSTS=""
if [ -n "$DRUPAL_TRUSTED_HOST_PATTERNS" ]; then
    IFS=',' read -ra PATTERNS <<< "$DRUPAL_TRUSTED_HOST_PATTERNS"
    for pattern in "${PATTERNS[@]}"; do
        TRUSTED_HOSTS="$TRUSTED_HOSTS  '$pattern',\n"
    done
fi

# Add Coolify-generated hostnames to trusted hosts
if [ -n "$COOLIFY_URL" ]; then
    # Extract hostname from URL
    COOLIFY_HOST=$(echo "$COOLIFY_URL" | sed -e 's|^[^/]*//||' -e 's|/.*$||' -e 's|:.*$||')
    COOLIFY_PATTERN=$(echo "$COOLIFY_HOST" | sed 's/\./\\\\./g')
    TRUSTED_HOSTS="$TRUSTED_HOSTS  '^${COOLIFY_PATTERN}\$',\n"
fi

# Always allow localhost
TRUSTED_HOSTS="$TRUSTED_HOSTS  '^localhost\$',\n"
TRUSTED_HOSTS="$TRUSTED_HOSTS  '^127\\\\.0\\\\.0\\\\.1\$',\n"

# Append database and other settings to settings.php if not already configured
if ! grep -q "Added by entrypoint" "$SETTINGS_FILE" 2>/dev/null; then
    echo "Configuring settings.php..."
    cat >> "$SETTINGS_FILE" << SETTINGS

// Added by entrypoint
\$databases['default']['default'] = [
  'database' => '${DB_NAME:-opensocial}',
  'username' => '${DB_USER:-opensocial}',
  'password' => '${DB_PASSWORD}',
  'prefix' => '',
  'host' => '${DB_HOST:-postgres}',
  'port' => '${DB_PORT:-5432}',
  'isolation_level' => 'READ COMMITTED',
  'driver' => 'pgsql',
  'namespace' => 'Drupal\\pgsql\\Driver\\Database\\pgsql',
  'autoload' => 'core/modules/pgsql/src/Driver/Database/pgsql/',
];

\$settings['hash_salt'] = '${DRUPAL_HASH_SALT}';
\$settings['config_sync_directory'] = '../config/sync';
\$settings['file_private_path'] = '${PRIVATE_DIR}';

\$settings['trusted_host_patterns'] = [
$(echo -e "$TRUSTED_HOSTS")];

// Reverse proxy settings for Coolify/Traefik
if (${DRUPAL_REVERSE_PROXY:-true}) {
  \$settings['reverse_proxy'] = TRUE;
  \$settings['reverse_proxy_addresses'] = ['127.0.0.1', '172.16.0.0/12', '192.168.0.0/16', '10.0.0.0/8'];
  \$settings['reverse_proxy_trusted_headers'] = 
    \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR |
    \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO |
    \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PORT;
}
SETTINGS
    chown www-data:www-data "$SETTINGS_FILE"
fi

# Check if Drupal is already installed
cd "$DRUPAL_ROOT"

SITE_INSTALLED=$(../vendor/bin/drush status --field=bootstrap 2>/dev/null || echo "")

if [ "$SITE_INSTALLED" != "Successful" ]; then
    echo "=============================================="
    echo "Open Social not installed. Running Drush site:install..."
    echo "=============================================="
    
    # Run site install
    ../vendor/bin/drush site:install social \
        --db-url="pgsql://${DB_USER:-opensocial}:${DB_PASSWORD}@${DB_HOST:-postgres}:${DB_PORT:-5432}/${DB_NAME:-opensocial}" \
        --site-name="${DRUPAL_SITE_NAME:-Open Social}" \
        --account-name="${DRUPAL_ADMIN_USER:-admin}" \
        --account-pass="${DRUPAL_ADMIN_PASS:-admin}" \
        --account-mail="${DRUPAL_ADMIN_EMAIL:-admin@example.com}" \
        --locale=en \
        -y
    
    echo "=============================================="
    echo "Open Social installation complete!"
    echo "=============================================="
else
    echo "Open Social already installed, skipping installation."
fi

# Ensure proper permissions after install
chown -R www-data:www-data "$FILES_DIR"
chown -R www-data:www-data "$PRIVATE_DIR"

# Run any pending database updates
echo "Running database updates..."
cd "$DRUPAL_ROOT"
../vendor/bin/drush updatedb -y || true

# Clear cache
echo "Clearing cache..."
../vendor/bin/drush cache:rebuild || true

echo "=============================================="
echo "Starting Apache..."
echo "=============================================="

# Execute the main container command (apache)
exec "$@"
