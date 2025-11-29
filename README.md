# Open Social 13.0.0-beta2 for Coolify

Docker Compose setup for deploying Open Social 13.0.0-beta2 with PostgreSQL on Coolify.

## How It Works

The Dockerfile creates a custom `composer.json` that requires `goalgorilla/open_social:13.0.0-beta2` directly from Packagist (the `social_template` doesn't have versioned releases for 13.x). This gives you a proper Composer-managed installation with Drush included.

## Quick Start for Coolify

### Option 1: Deploy via Git Repository

1. Push this folder to a Git repository (GitHub, GitLab, etc.)

2. In Coolify:
   - Go to **Projects** → Select your project → **New Resource**
   - Choose **Docker Compose**
   - Select your Git repository
   - Coolify will auto-detect the `docker-compose.yml`

3. Configure environment variables in Coolify's UI:
   ```
   DB_PASSWORD=<generate-secure-password>
   DRUPAL_HASH_SALT=<generate-with: openssl rand -hex 32>
   DRUPAL_TRUSTED_HOST_PATTERNS=^yourdomain\.com$,^www\.yourdomain\.com$
   ```

4. Deploy!

### Option 2: Deploy via Raw Docker Compose

1. In Coolify: **New Resource** → **Docker Compose (Empty)**

2. Paste the contents of `docker-compose.yml`

3. You'll also need to upload the Dockerfile and entrypoint.sh, or use a pre-built image

## Post-Deployment Setup

**Auto-install is now enabled!** On first deploy, Drush will automatically install Open Social using the environment variables you've configured. Just wait for the container to start and the site will be ready.

You can customize the install via environment variables:
- `DRUPAL_SITE_NAME` - Your community name (default: "Open Social")
- `DRUPAL_ADMIN_USER` - Admin username (default: "admin")
- `DRUPAL_ADMIN_PASS` - Admin password (default: "admin") - **change this!**
- `DRUPAL_ADMIN_EMAIL` - Admin email (default: "admin@example.com")

### Manual Installation (if needed)

If you prefer manual control, you can exec into the container:

```bash
# Exec into the container
docker exec -it <container_name> bash

# Run site install manually
cd /var/www/html
./vendor/bin/drush site:install social \
  --site-name="Your Community" \
  --account-name=admin \
  --account-pass=admin123 \
  -y
```

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `DB_HOST` | PostgreSQL host | `postgres` |
| `DB_PORT` | PostgreSQL port | `5432` |
| `DB_NAME` | Database name | `opensocial` |
| `DB_USER` | Database user | `opensocial` |
| `DB_PASSWORD` | Database password | (required) |
| `DRUPAL_HASH_SALT` | Drupal hash salt | (auto-generated, set for production) |
| `DRUPAL_TRUSTED_HOST_PATTERNS` | Comma-separated regex patterns | (auto-detected from Coolify) |
| `DRUPAL_REVERSE_PROXY` | Enable reverse proxy settings | `true` |
| `DRUPAL_SITE_NAME` | Site name for auto-install | `Open Social` |
| `DRUPAL_ADMIN_USER` | Admin username for auto-install | `admin` |
| `DRUPAL_ADMIN_PASS` | Admin password for auto-install | `admin` |
| `DRUPAL_ADMIN_EMAIL` | Admin email for auto-install | `admin@example.com` |

## Volumes

| Volume | Purpose |
|--------|---------|
| `opensocial_files` | Public uploaded files |
| `opensocial_private` | Private files (downloads, etc.) |
| `postgres_data` | PostgreSQL data persistence |

## Coolify-Specific Notes

### Domain Configuration
Coolify will handle SSL/TLS via Traefik. Make sure to:
1. Set your domain in Coolify's resource settings
2. Update `DRUPAL_TRUSTED_HOST_PATTERNS` to match your domain

### Health Checks
The compose file includes health checks. Coolify will show the service as healthy once:
- PostgreSQL accepts connections
- Apache serves the Drupal install page

### Scaling
For production, consider:
- Adding Redis for caching (Open Social supports it)
- Separating the database to a managed PostgreSQL service
- Adding backup jobs for volumes

## Troubleshooting

### Check logs
```bash
# In Coolify UI, click on the service and view logs
# Or via CLI:
docker logs <container_name>
```

### Database connection issues
```bash
# Test connection from app container
docker exec -it <app_container> bash
nc -zv postgres 5432
```

### Permission issues
```bash
# Fix file permissions
docker exec -it <app_container> bash
chown -R www-data:www-data /var/www/html/html/sites/default/files
chown -R www-data:www-data /var/www/private
```

## Building Locally

```bash
docker compose build
docker compose up -d
# Visit http://localhost (or your configured port)
```
