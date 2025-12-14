# Custom Modules

Place custom Drupal modules here that aren't available on drupal.org or via Composer.

Modules in this directory are automatically copied to `/var/www/html/html/modules/custom` in the Docker container and owned by `www-data`.

## Usage

1. Add your module directory here (e.g., `modules/custom/my_module/`)
2. Rebuild the container: `docker compose up -d --build`
3. Enable via Drush: `drush en my_module -y`

## Notes

- For modules available on drupal.org, prefer adding them to `composer.json` instead
- The `.gitkeep` file ensures this directory is tracked in git even when empty
