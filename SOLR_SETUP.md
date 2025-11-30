# Solr Search Integration for Open Social

This document explains how Solr search has been integrated into your Open Social installation.

## Overview

Apache Solr has been integrated with your Open Social installation using the Search API Solr module. This provides powerful search capabilities for your social platform, enabling efficient indexing and searching of content.

## Components

### 1. Modules
- **search_api**: Provides the search framework
- **search_api_solr**: Provides the Solr backend for search_api

### 2. Services
- **Solr (8.11)**: Running in a separate Docker container
- **Port**: 8983 (exposed to host for access)

### 3. Configuration
- **Core name**: `opensearch`
- **Host**: `solr` (Docker service name)
- **Port**: 8983
- **Path**: `/solr`

## Configuration Details

### Environment Variables

The following environment variables are used to configure Solr in the `docker-compose.yml`:

```yaml
SOLR_HOST: solr
SOLR_PORT: 8983
SOLR_PATH: /solr
```

### Drupal Configuration

The entrypoint script automatically adds Solr configuration to Drupal's settings.php:

```php
// Solr configuration
if (getenv('SOLR_HOST') && getenv('SOLR_PORT')) {
  $config['search_api.server.solr_server']['plugin'] = 'search_api_solr';
  $config['search_api.server.solr_server']['configuration']['host'] = '${SOLR_HOST:-solr}';
  $config['search_api.server.solr_server']['configuration']['port'] = '${SOLR_PORT:-8983}';
  $config['search_api.server.solr_server']['configuration']['path'] = '${SOLR_PATH:-/solr}';
  $config['search_api.server.solr_server']['configuration']['core'] = 'opensearch';
  $config['search_api.server.solr_server']['configuration']['http_method'] = 'POST';
}
```

## Solr Configuration Files

The Solr configuration is located in the `solr-config/` directory and includes:

- `solrconfig.xml`: Core Solr configuration
- `schema.xml`: Defines the fields and field types for indexing
- `stopwords.txt`: List of words to ignore during indexing
- `synonyms.txt`: Synonym mappings for search expansion
- `solrconfig_extra.xml`: Additional Search API Solr configuration
- `core.properties`: Core-specific properties

## Usage

### Initial Setup

When you start the services using `docker-compose up`, the following happens:

1. The Solr service starts and creates the `opensearch` core using the provided configuration
2. The Open Social service waits for both MariaDB and Solr to be available
3. Drupal is configured with the Solr backend via the settings.php modifications
4. The Search API Solr module is enabled

### Post-Installation Configuration

After your Open Social site is up and running:

1. Navigate to `/admin/config/search/search-api`
2. You should see a server named "solr_server" that was automatically created
3. The server should be marked as "Available"
4. You can create or edit search indexes to use this Solr server
5. By default, there might be an index to index content, but you'll need to configure it based on your needs

### Managing Solr

#### Accessing Solr Admin UI
You can access the Solr admin interface at: `http://localhost:8983/solr/`

#### Indexing Content
To index existing content:
1. Go to the Search API configuration page (`/admin/config/search/search-api`)
2. Find your Solr index
3. Click "Index now" to index all content

Alternatively, you can use Drush:
```
drush sapi-i  # Index all pending items
drush sapi-t  # Track items for indexing
```

## Troubleshooting

### Solr Service Not Starting

If the Solr service fails to start, check:
- The configuration files in `solr-config/` directory
- Log output with `docker-compose logs solr`
- Ensure the core creation command is working: `solr-precreate opensearch /configsets/opensearch`

### Drupal Can't Connect to Solr

If Drupal reports that Solr is unavailable:
1. Verify the Solr service is running: `docker-compose ps`
2. Check that the host, port, and core name match in both `docker-compose.yml` and Drupal's configuration
3. Ensure the network connectivity between the containers

### Search Not Working

If searches return no results:
1. Verify content has been indexed by checking the Solr admin UI
2. Check that your Search API indexes are properly configured
3. Confirm that the correct server is selected for your indexes

## Customization

### Modifying Solr Configuration

To modify Solr's behavior:
1. Edit the configuration files in the `solr-config/` directory
2. Restart the Solr service: `docker-compose restart solr`
3. Re-index your content if schema changes require it

### Adding Search Indexes

To add new search indexes:
1. Go to `/admin/config/search/search-api`
2. Click "Add index"
3. Select your Solr server as the backend
4. Configure the index with the data sources and fields you want to include
5. Save the index and begin indexing content

## Security Considerations

- The Solr port (8983) is exposed to the host machine, so ensure appropriate network security measures are in place
- In production, consider adding authentication to Solr
- Review the schema.xml to ensure sensitive data isn't inadvertently indexed

## Performance

The provided Solr configuration is a basic setup. For production environments, consider:
- Tuning Solr's memory settings
- Optimizing schema.xml for your specific search requirements
- Configuring appropriate caching settings
- Setting up Solr clustering for high availability

## Updates

When updating the Solr module in Drupal:
- Check the Search API Solr module's documentation for any required Solr configuration changes
- Update the `solr-config/` files if new configuration is needed
- Test search functionality after the update