# Project Context for Open Social Coolify Deployment

## Overview
This project contains a Docker Compose setup for deploying Open Social 13.0.0-beta2 on Coolify, a self-hosted deployment platform. Open Social is an open-source social networking platform built on Drupal that provides community features like groups, events, topics, and user profiles.

## Architecture
The deployment consists of two main services:
1. **Open Social Web Service** - Runs the Open Social application using Apache and PHP
2. **MariaDB Database** - Stores all Open Social data

## Key Components

### Dockerfile
The Dockerfile creates a custom PHP 8.3 Apache image with all the necessary dependencies for running Open Social:
- PHP extensions: PDO, MySQL, GD, MBString, EXIF, PCNTL, BCMath, ZIP, Opcache, Intl
- Apache modules: rewrite, headers, expires
- Composer for dependency management
- Custom PHP configuration optimized for Drupal

### composer.json
- Specifies Open Social version 13.0.0-beta2
- Includes Drush for command-line operations
- Configures Drupal scaffolding to install to the `html` directory
- Sets up custom installer paths for Drupal components

### entrypoint.sh
The entrypoint script handles the initialization of the Open Social container:
- Waits for the database to be ready
- Creates necessary directory structures
- Generates settings.php with database and security configurations
- Performs automatic installation if the site isn't already installed
- Runs database updates and clears cache

### docker-compose.yml
Defines the services required for the Open Social deployment:
- Opensocial service with environment variables for configuration
- MariaDB service with health checks
- Persistent volumes for files and database storage

## Environment Variables
Various environment variables control the deployment:

### Database Configuration
- `DB_HOST`: MariaDB host (default: mariadb)
- `DB_PORT`: MariaDB port (default: 3306)
- `DB_NAME`: Database name (default: opensocial)
- `DB_USER`: Database user (default: opensocial)
- `DB_PASSWORD`: Database password (required)

### Drupal Configuration
- `DRUPAL_HASH_SALT`: Salt for hashing (required for production)
- `DRUPAL_TRUSTED_HOST_PATTERNS`: Regex patterns for trusted hosts
- `DRUPAL_REVERSE_PROXY`: Enable reverse proxy settings (default: true)

### Site Installation
- `DRUPAL_SITE_NAME`: Site name for auto-install (default: Open Social)
- `DRUPAL_ADMIN_USER`: Admin username for auto-install (default: admin)
- `DRUPAL_ADMIN_PASS`: Admin password for auto-install (default: admin)
- `DRUPAL_ADMIN_EMAIL`: Admin email for auto-install (default: admin@example.com)

## Volumes
Three persistent volumes ensure data persistence:
- `opensocial_files`: Public uploaded files
- `opensocial_private`: Private files (downloads, etc.)
- `mariadb_data`: MariaDB data persistence

## Coolify Integration
The setup is designed specifically for deployment on Coolify:
- Health checks for both services
- Automatic installation during first deployment
- Proper configuration for reverse proxy handling
- Environment variables that Coolify automatically provides

## Additional Module Configuration
- The GraphQL module is automatically enabled during installation or when the container starts
- If the site is already installed, the entrypoint script checks if the GraphQL module is enabled and enables it if necessary

## Database Compatibility
- Switched from PostgreSQL to MariaDB to resolve compatibility issues with the Flag module and other Open Social components
- MariaDB is the recommended database for Open Social and Drupal

## Building and Running
### Locally
```bash
docker compose build
docker compose up -d
```

### On Coolify
The project can be deployed to Coolify either via Git repository or raw Docker Compose.

## Post-Deployment
After deployment, the application will automatically install itself using the provided environment variables. The site will be accessible via the configured domain.

## Troubleshooting
- Check service logs through Coolify UI or CLI
- Verify database connection using `nc -zv mariadb 3306` from the app container
- Fix file permissions if needed using `chown -R www-data:www-data`

## Security Considerations
- Change default admin credentials immediately
- Generate a secure hash salt for production
- Configure proper trusted host patterns
- Use strong database passwords