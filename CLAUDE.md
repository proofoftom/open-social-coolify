# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **production-ready Docker Compose deployment** for **Drupal Open Social 13.0.0-beta2**, specifically designed for **Coolify** (self-hosted deployment platform). This directory is separate from the development environments (`/social`, `/quasar`) in the parent repository.

**Services:**
- **opensocial**: PHP 8.3-Apache with Drupal Open Social
- **mariadb**: MariaDB 10.11 database
- **solr**: Apache Solr 8.11 search engine with custom configuration

## Development Commands

### Docker Compose Operations

```bash
# Build and start all services
docker compose up -d --build

# View logs
docker compose logs -f opensocial
docker compose logs -f solr
docker compose logs -f mariadb

# Check service health
docker compose ps

# Restart a specific service
docker compose restart solr

# Stop all services
docker compose down

# Clean rebuild (CAUTION: removes all data)
docker compose down -v
docker compose up -d --build
```

### Drupal Operations (via Drush)

```bash
# Access container
docker exec -it <container_name> bash

# Navigate to Drupal root
cd /var/www/html/html

# Drush commands (from within container)
../../vendor/bin/drush status
../../vendor/bin/drush cache:rebuild
../../vendor/bin/drush updatedb
../../vendor/bin/drush config:export
../../vendor/bin/drush config:import

# Enable/disable modules
../../vendor/bin/drush en module_name -y
../../vendor/bin/drush pm:uninstall module_name -y

# Solr indexing
../../vendor/bin/drush sapi-i    # Index all pending items
../../vendor/bin/drush sapi-t    # Track items for indexing
../../vendor/bin/drush sapi-c    # Clear search index
```

### Database Operations

```bash
# Backup database
docker exec mariadb mysqldump -u opensocial -p$DB_PASSWORD opensocial > backup.sql

# Restore database
cat backup.sql | docker exec -i mariadb mysql -u opensocial -p$DB_PASSWORD opensocial

# Access MySQL CLI
docker exec -it mariadb mysql -u opensocial -p
```

## Architecture

### Service Configuration

**opensocial (Drupal Application)**
- Base: `php:8.3-apache`
- Document root: `/var/www/html/html` (Drupal scaffold structure)
- PHP memory: 512M
- Upload/post max: 64M
- Extensions: PDO_MySQL, mbstring, exif, pcntl, bcmath, gd, zip, opcache, intl
- Apache modules: rewrite, headers, expires
- Health check: HTTP /core/install.php (30s interval, 60s start period)

**mariadb (Database)**
- Version: 10.11
- Character set: utf8mb4
- Collation: utf8mb4_unicode_ci
- Health check: mysqladmin ping (10s interval, 30s start period)

**solr (Search Engine)**
- Version: 8.11
- Core name: `drupal`
- Configset: `opensearch` (custom, 21 config files)
- NOT exposed to host (internal network only)
- Health check: HTTP /solr/ (30s interval, 60s start period)

### Persistent Volumes

```yaml
opensocial_files:    /var/www/html/html/sites/default/files  # Public uploads
opensocial_private:  /var/www/private                         # Private files
mariadb_data:        /var/lib/mysql                           # Database
solr_data:           /var/solr                                # Search index
```

### entrypoint.sh Workflow

The `entrypoint.sh` script orchestrates container initialization:

1. **Wait for database** (netcat TCP check)
2. **Create database** if missing (using root credentials)
3. **Wait for Solr** if configured
4. **Create directories** for files and private storage
5. **Generate settings.php**:
   - Database credentials
   - Hash salt (auto-generated if not provided)
   - Trusted host patterns (from Coolify vars + DRUPAL_TRUSTED_HOST_PATTERNS)
   - Solr connection configuration
   - Reverse proxy settings for Traefik
6. **Detect installation state**:
   - **Not installed**: Run `drush site:install social`
   - **Already installed**: Skip to module enablement check
7. **Enable modules**: graphql, search_api, search_api_solr
8. **Post-install**: Run updatedb and cache rebuild
9. **Start Apache**

## Environment Variables

### Required for Production

**Database:**
- `DB_PASSWORD` - **REQUIRED** - Database password (change from default)
- `DB_ROOT_PASSWORD` - **REQUIRED** - MySQL root password

**Drupal Security:**
- `DRUPAL_HASH_SALT` - **CRITICAL** - Cryptographic salt (generate with: `openssl rand -hex 32`)
- `DRUPAL_TRUSTED_HOST_PATTERNS` - **CRITICAL** - Comma-separated regex (e.g., `^yourdomain\.com$,^www\.yourdomain\.com$`)
- `DRUPAL_ADMIN_PASS` - **REQUIRED** - Admin password (change from default `admin`)

### Optional Configuration

**Database:**
- `DB_HOST` (default: `mariadb`)
- `DB_PORT` (default: `3306`)
- `DB_NAME` (default: `opensocial`)
- `DB_USER` (default: `opensocial`)

**Drupal:**
- `DRUPAL_REVERSE_PROXY` (default: `true`)
- `DRUPAL_SITE_NAME` (default: `Open Social`)
- `DRUPAL_ADMIN_USER` (default: `admin`)
- `DRUPAL_ADMIN_EMAIL` (default: `admin@example.com`)

**Solr:**
- `SOLR_HOST` (default: `solr`)
- `SOLR_PORT` (default: `8983`)
- `SOLR_PATH` (default: `/solr`)
- `SOLR_HEAP` (default: `512m`)

**Coolify (auto-detected):**
- `SERVICE_FQDN_OPENSOCIAL`
- `SERVICE_URL_OPENSOCIAL`
- `COOLIFY_FQDN`
- `COOLIFY_URL`

## Solr Integration

### Configuration Files

The `solr-config/` directory contains 21 files:

**Core configuration:**
- `solrconfig.xml` - Main Solr configuration
- `schema.xml` - Field definitions and types
- `core.properties` - Core metadata
- `solrcore.properties` - Additional properties

**Search API Solr integration:**
- `solrconfig_extra.xml` - Search components (spellcheck, suggest)
- `solrconfig_query.xml` - Query caching
- `solrconfig_index.xml` - Indexing settings
- `solrconfig_requestdispatcher.xml` - HTTP cache settings
- `schema_extra_fields.xml` - Additional field definitions
- `schema_extra_types.xml` - Field type extensions

**Linguistic processing:**
- `stopwords*.txt` - Words to exclude from search
- `synonyms*.txt` - Search term expansion
- `accents*.txt` - Accent normalization
- `protwords*.txt` - Protected terms

**Query enhancement:**
- `elevate.xml` - Result elevation rules

### Solr Startup

The Solr container runs this command:
```bash
solr-precreate drupal /opt/solr/server/solr/configsets/opensearch
```

This:
1. Removes any corrupted core data: `rm -rf /var/solr/data/drupal`
2. Creates the `drupal` core using the `opensearch` configset
3. Starts Solr in foreground mode

### Drupal Configuration

The `entrypoint.sh` automatically configures Solr in `settings.php`:
```php
$config['search_api.server.solr_server']['backend'] = 'search_api_solr';
$config['search_api.server.solr_server']['backend_config']['connector_config']['host'] = 'solr';
$config['search_api.server.solr_server']['backend_config']['connector_config']['port'] = '8983';
$config['search_api.server.solr_server']['backend_config']['connector_config']['path'] = '/solr';
$config['search_api.server.solr_server']['backend_config']['connector_config']['core'] = 'drupal';
```

### Managing Search Indexes

**Via Drupal UI:**
- Navigate to `/admin/config/search/search-api`
- Server should show as "Available"
- Create or edit indexes to use the Solr backend

**Via Drush:**
```bash
drush sapi-i    # Index all pending items
drush sapi-t    # Track items for indexing
drush sapi-c    # Clear index and reindex
```

## Common Workflows

### Deploying to Coolify

1. Create new resource: **Docker Compose**
2. Select Git repository (this directory)
3. Coolify auto-detects `docker-compose.yml`
4. Set environment variables in UI:
   ```
   DB_PASSWORD=<secure_password>
   DB_ROOT_PASSWORD=<secure_root_password>
   DRUPAL_HASH_SALT=<run: openssl rand -hex 32>
   DRUPAL_TRUSTED_HOST_PATTERNS=^yourdomain\.com$
   DRUPAL_ADMIN_PASS=<secure_admin_password>
   ```
5. Deploy and wait for health checks

### Troubleshooting Database Connection

```bash
# Test TCP connectivity
docker exec -it opensocial bash
nc -zv mariadb 3306

# Test MySQL connection
mysql -h mariadb -u opensocial -p$DB_PASSWORD opensocial

# Common issues:
# - DB_PASSWORD doesn't match MYSQL_PASSWORD in docker-compose
# - MariaDB health check hasn't passed yet (wait 30s)
# - Database not created (check entrypoint.sh logs)
```

### Troubleshooting Solr

```bash
# Test TCP connectivity
docker exec -it opensocial bash
nc -zv solr 8983

# Check Solr status
curl -s http://solr:8983/solr/admin/cores?action=STATUS

# Common issues:
# - Corrupted core data: restart Solr service or clear volume
# - Configset not found: verify Dockerfile.solr copied files correctly
# - Modules not enabled: check drush status for search_api_solr
```

### Fixing File Permissions

```bash
docker exec -it opensocial bash
chown -R www-data:www-data /var/www/html/html/sites/default/files
chown -R www-data:www-data /var/www/private
chmod -R 755 /var/www/html/html/sites/default/files
```

### Rebuilding Solr Core

```bash
# If configuration changed
docker compose restart solr

# If core is corrupted (CAUTION: deletes search index)
docker compose down solr
docker volume rm open-social-coolify_solr_data
docker compose up -d solr

# Then reindex
docker exec opensocial bash
cd /var/www/html/html
../../vendor/bin/drush sapi-i
```

### Updating Drupal Configuration

```bash
# Export current config (from container)
cd /var/www/html/html
../../vendor/bin/drush config:export

# Import config from codebase
../../vendor/bin/drush config:import -y

# Handle config changes during deployment
../../vendor/bin/drush updatedb -y
../../vendor/bin/drush config:import -y
../../vendor/bin/drush cache:rebuild
```

## Security Considerations

### Production Checklist

**CRITICAL:**
- [ ] Change `DB_PASSWORD` and `DB_ROOT_PASSWORD`
- [ ] Generate and set `DRUPAL_HASH_SALT`: `openssl rand -hex 32`
- [ ] Set `DRUPAL_TRUSTED_HOST_PATTERNS` to your domain regex
- [ ] Change `DRUPAL_ADMIN_PASS` from default `admin`

**Recommended:**
- [ ] Enable HTTPS via Coolify/Traefik SSL
- [ ] Restrict database access to Docker network only
- [ ] Review Drupal permissions and roles
- [ ] Enable Drupal security updates
- [ ] Set up database backups
- [ ] Monitor disk usage for volumes

**Network Security:**
- Solr port 8983 is NOT exposed to host (internal network only)
- Database port 3306 is NOT exposed to host
- Only port 80 (HTTP) is exposed, proxied by Traefik to HTTPS

## Important Notes

### Database Evolution
- **Previous**: PostgreSQL (had compatibility issues)
- **Current**: MariaDB 10.11 (full Open Social compatibility)
- Migration required if upgrading from PostgreSQL deployment

### Auto-Enabled Modules
The deployment automatically enables:
- `graphql` - GraphQL API support
- `search_api` - Search framework
- `search_api_solr` - Solr backend

Additional modules can be enabled via:
```bash
drush en module_name -y
```

### Settings.php Management
The `entrypoint.sh` script appends configuration to `settings.php` with a marker:
```php
// Added by entrypoint.sh - DO NOT EDIT BELOW THIS LINE
```

This prevents duplicate configuration on container restarts. If you need to modify auto-generated settings, edit `entrypoint.sh` and redeploy.

### Health Check Timing
All services have health checks with start periods:
- **opensocial**: 60s start period, 30s interval
- **mariadb**: 30s start period, 10s interval
- **solr**: 60s start period, 30s interval

First deployment may take 2-3 minutes for all services to become healthy.

### Performance Tuning

**For larger sites, adjust:**
```yaml
environment:
  - SOLR_HEAP=1024m              # Default: 512m
  - PHP_MEMORY_LIMIT=1024M       # Edit Dockerfile
```

**Consider adding:**
- Redis for caching (Open Social supports it)
- CDN for static assets
- Read replicas for database (if high traffic)

## File Structure

```
/open-social-coolify/
├── Dockerfile                   # PHP 8.3-Apache image
├── Dockerfile.solr              # Solr 8.11 with custom config
├── docker-compose.yml           # Service orchestration
├── entrypoint.sh               # Container initialization script
├── composer.json               # PHP dependencies (Open Social)
├── composer.lock               # Locked dependency versions
├── solr-config/                # 21 Solr configuration files
│   ├── solrconfig.xml          # Main Solr config
│   ├── schema.xml              # Search schema
│   ├── solrconfig_extra.xml    # Search components
│   ├── schema_extra_*.xml      # Field/type extensions
│   └── *.txt                   # Linguistic files
├── README.md                   # Coolify deployment guide
├── SOLR_SETUP.md               # Solr integration docs
└── QWEN.md                     # Project context

Generated at runtime:
/var/www/html/                  # Composer creates this
/var/www/html/html/             # Drupal web root
/var/www/private/               # Private file storage
```

## Version Information

- **Open Social**: 13.0.0-beta2
- **Drupal**: 10.x (via Open Social)
- **PHP**: 8.3
- **MariaDB**: 10.11
- **Apache Solr**: 8.11
- **Apache HTTP**: 2.4 (from php:8.3-apache)
