#!/bin/bash
set -e

# Define paths
DRUPAL_ROOT="/var/www/html/html"
PROJECT_ROOT="/var/www/html"
DRUSH="$PROJECT_ROOT/vendor/bin/drush"
SETTINGS_FILE="$DRUPAL_ROOT/sites/default/settings.php"
DEFAULT_SETTINGS="$DRUPAL_ROOT/sites/default/default.settings.php"
FILES_DIR="$DRUPAL_ROOT/sites/default/files"
PRIVATE_DIR="/var/www/private"

# Wait for database
echo "Waiting for database at ${DB_HOST:-mariadb}:${DB_PORT:-3306}..."
while ! nc -z "${DB_HOST:-mariadb}" "${DB_PORT:-3306}"; do
    sleep 1
done
echo "Database host is available!"

# Wait for Solr if configured
if [ -n "${SOLR_HOST:-}" ] && [ -n "${SOLR_PORT:-}" ]; then
    echo "Waiting for Solr at ${SOLR_HOST:-}:${SOLR_PORT:-}..."
    while ! nc -z "${SOLR_HOST:-}" "${SOLR_PORT:-}"; do
        sleep 1
    done
    echo "Solr is available!"
fi

# Create the database if it doesn't exist
echo "Creating database if it doesn't exist..."
mysql -h "${DB_HOST:-mariadb}" -P "${DB_PORT:-3306}" -u "root" -p"${DB_ROOT_PASSWORD:-rootpassword}" --skip-ssl -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME:-opensocial}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
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
  'host' => '${DB_HOST:-mariadb}',
  'port' => '${DB_PORT:-3306}',
  'driver' => 'mysql',
  'namespace' => 'Drupal\\Core\\Database\\Driver\\mysql',
  'charset' => 'utf8mb4',
  'collation' => 'utf8mb4_general_ci',
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

// Solr configuration
if (getenv('SOLR_HOST') && getenv('SOLR_PORT')) {
  \$config['search_api.server.solr_server']['plugin'] = 'search_api_solr';
  \$config['search_api.server.solr_server']['configuration']['host'] = '${SOLR_HOST:-solr}';
  \$config['search_api.server.solr_server']['configuration']['port'] = '${SOLR_PORT:-8983}';
  \$config['search_api.server.solr_server']['configuration']['path'] = '${SOLR_PATH:-/solr}';
  \$config['search_api.server.solr_server']['configuration']['core'] = 'drupal';
  \$config['search_api.server.solr_server']['configuration']['http_method'] = 'POST';
}
SETTINGS
    chown www-data:www-data "$SETTINGS_FILE"
fi

# Check if Drupal is already installed
cd "$DRUPAL_ROOT"

SITE_INSTALLED=$($DRUSH status --field=bootstrap 2>/dev/null || echo "")

if [ "$SITE_INSTALLED" != "Successful" ]; then
    echo "=============================================="
    echo "Open Social not installed. Running Drush site:install..."
    echo "=============================================="

    # Run site install
    $DRUSH site:install social \
        --db-url="mysql://${DB_USER:-opensocial}:${DB_PASSWORD}@${DB_HOST:-mariadb}:${DB_PORT:-3306}/${DB_NAME:-opensocial}" \
        --site-name="${DRUPAL_SITE_NAME:-Open Social}" \
        --account-name="${DRUPAL_ADMIN_USER:-admin}" \
        --account-pass="${DRUPAL_ADMIN_PASS:-admin}" \
        --account-mail="${DRUPAL_ADMIN_EMAIL:-admin@example.com}" \
        --locale=en \
        -y

    echo "=============================================="
    echo "Open Social installation complete!"
    echo "=============================================="

    # Enable the GraphQL and Search API Solr modules after installation
    echo "Enabling GraphQL module..."
    $DRUSH en graphql -y || echo "Failed to enable GraphQL module"

    echo "Enabling Search API module..."
    $DRUSH en search_api -y || echo "Failed to enable Search API module"

    echo "Enabling Search API Solr module..."
    $DRUSH en search_api_solr -y || echo "Failed to enable Search API Solr module"

    echo "Installing demo content..."
    $DRUSH en social_demo -y || echo "Failed to enable social_demo module"
    $DRUSH cr || echo "Failed to clear cache"
    $DRUSH social-demo:add file user group topic event event_enrollment comment post like || echo "Failed to add demo content"

    echo "Enabling Web3 modules..."
    $DRUSH en siwe_login safe_smart_accounts group_treasury social_group_treasury -y || echo "Failed to enable Web3 modules"

else
    echo "Open Social already installed, skipping installation."

    # Enable the GraphQL module if not already enabled
    echo "Checking if GraphQL module is enabled..."
    if ! $DRUSH pm-list --field=status --filter='graphql' | grep -q "Enabled"; then
        echo "Enabling GraphQL module..."
        $DRUSH en graphql -y || echo "Failed to enable GraphQL module"
    else
        echo "GraphQL module is already enabled."
    fi

    # Enable the Search API modules if not already enabled
    echo "Checking if Search API modules are enabled..."
    if ! $DRUSH pm-list --field=status --filter='search_api' | grep -q "Enabled"; then
        echo "Enabling Search API module..."
        $DRUSH en search_api -y || echo "Failed to enable Search API module"
    else
        echo "Search API module is already enabled."
    fi

    if ! $DRUSH pm-list --field=status --filter='search_api_solr' | grep -q "Enabled"; then
        echo "Enabling Search API Solr module..."
        $DRUSH en search_api_solr -y || echo "Failed to enable Search API Solr module"
    else
        echo "Search API Solr module is already enabled."
    fi

    # Enable Web3 modules if not already enabled
    echo "Checking if Web3 modules are enabled..."
    if ! $DRUSH pm-list --field=status --filter='social_group_treasury' | grep -q "Enabled"; then
        echo "Enabling Web3 modules..."
        $DRUSH en siwe_login safe_smart_accounts group_treasury social_group_treasury -y || echo "Failed to enable Web3 modules"
    else
        echo "Web3 modules are already enabled."
    fi
fi

# Configure SIWE Login expected domain (for both fresh and existing installations)
if $DRUSH pm-list --field=status --filter='siwe_login' | grep -q "Enabled"; then
    echo "Configuring SIWE Login expected domain..."
    SIWE_DOMAIN=""

    # Try to get domain from SERVICE_FQDN_OPENSOCIAL (Coolify variable)
    if [ -n "${SERVICE_FQDN_OPENSOCIAL:-}" ]; then
        SIWE_DOMAIN="$SERVICE_FQDN_OPENSOCIAL"
    # Fall back to extracting from COOLIFY_URL
    elif [ -n "${COOLIFY_URL:-}" ]; then
        SIWE_DOMAIN=$(echo "$COOLIFY_URL" | sed -e 's|^[^/]*//||' -e 's|/.*$||' -e 's|:.*$||')
    # Fall back to first trusted host pattern if set
    elif [ -n "$DRUPAL_TRUSTED_HOST_PATTERNS" ]; then
        # Extract first pattern (remove regex anchors if present)
        SIWE_DOMAIN=$(echo "$DRUPAL_TRUSTED_HOST_PATTERNS" | cut -d',' -f1 | sed 's/[\^$]//g' | sed 's/\\\\/\//g')
    fi

    if [ -n "$SIWE_DOMAIN" ]; then
        echo "Setting SIWE Login expected domain to: $SIWE_DOMAIN"
        $DRUSH config:set siwe_login.settings expected_domain "$SIWE_DOMAIN" -y || echo "Failed to configure SIWE Login domain"
    else
        echo "Warning: Could not determine domain for SIWE Login configuration"
    fi
fi

# Ensure proper permissions
chown -R www-data:www-data "$DRUPAL_ROOT/sites/default/files"
chown -R www-data:www-data "$PRIVATE_DIR"

# Run any pending database updates
echo "Running database updates..."
cd "$DRUPAL_ROOT"
$DRUSH updatedb -y || true

# Clear cache
echo "Clearing cache..."
$DRUSH cr || true

echo "=============================================="
echo "Starting Apache..."
echo "=============================================="

# Execute the main container command (apache)
exec "$@"
