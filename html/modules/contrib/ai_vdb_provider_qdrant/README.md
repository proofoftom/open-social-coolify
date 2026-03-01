# Qdrant Vector Database Provider

[![Status](https://img.shields.io/badge/Status-Experimental-orange)](https://github.com/your-repo)
[![Drupal](https://img.shields.io/badge/Drupal-10.2%2B%20%7C%2011.x-blue)](https://www.drupal.org)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://www.php.net)

Provides a drupal/ai VdbProvider plugin that interfaces with a Qdrant vector database instance.

> **⚠️ Experimental Module**: This module is in experimental status. Use with caution in production environments.

## Qdrant

Qdrant is a vector database that provides vector similarity search and storage capabilities via HTTP API.

See https://qdrant.tech/ for more information.

## Requirements

### Drupal Requirements
* **Drupal Core**: 10.2+ or 11.x
* **AI Module**: `drupal/ai` (latest stable version)
* **SearchAPI Module**: `drupal/search_api` (for search integration)
* **Key Module**: `drupal/key` (for secure API key storage)

### PHP Requirements
* **PHP**: 8.1 or higher
* **PHP Extensions**:
  - `curl` (for HTTP API communication)
  - `json` (for data serialization)
  - `mbstring` (for string handling)

### Infrastructure Requirements
* **Qdrant Server**: A running Qdrant instance accessible via HTTP/HTTPS
* **Network**: Web server must be able to reach Qdrant server
* **Memory**: Sufficient RAM for vector operations (depends on data size)

## Installation

### 1. Install via Composer
```bash
# Install the module (when published)
composer require drupal/ai_vdb_provider_qdrant

# Or install development version
composer require drupal/ai_vdb_provider_qdrant:dev-main
```

### 2. Enable the Module
```bash
drush en ai_vdb_provider_qdrant
```

### 3. Configure API Keys
1. Go to `/admin/config/system/keys`
2. Create a new key for your Qdrant API key (if required)
3. Choose appropriate key provider (file, environment variable, etc.)

## Interface Compliance

This module implements the `AiVdbProviderInterface` from the AI module, including:

- **getRawEmbeddingFieldName()**: Returns `'vector'` to indicate support for raw embedding vector access
- **Database parameter**: All methods accept a `$database` parameter (defaults to `'default'`), though Qdrant does not use database namespacing

The module is fully compliant with AI module version 1.1+ interface requirements.

### Known Limitations

- **Vector Retrieval**: While `getRawEmbeddingFieldName()` correctly returns `'vector'`, the actual vector retrieval in `QdrantClient` currently has `with_vector` hardcoded to `FALSE` in the search methods. This means raw vectors are not currently returned in search results. This limitation will be addressed in a future update.

## Configuration

### Basic Configuration
1. Navigate to `/admin/config/ai/vdb_providers/qdrant`
2. Configure the following settings:
   - **Host**: Your Qdrant server hostname or IP
   - **Port**: Qdrant server port (default: 6333)
   - **API Key**: Select the key created in installation step 3 (if required)
   - **Database**: Database name (default: 'default')

### SearchAPI Integration
1. Create a new SearchAPI server at `/admin/config/search/search-api/add-server`
2. Select "AI Search" as the backend
3. Configure the server with:
   - **VDB Provider**: Qdrant
   - **Collection Name**: Name for your vector collection
   - **Vector Dimensions**: Must match your embedding model (e.g., 1536 for OpenAI)
   - **Similarity Metric**: Choose from Cosine, Euclidean, or Dot Product

### Embedding Strategy Configuration
- Configure your embedding strategy (OpenAI, local models, etc.)
- Ensure vector dimensions match between embedding model and Qdrant collection


## Using with Docker Compose / ddev

An example docker compose setup of a Qdrant instance is provided.

See `./docs/docker-compose-examples/qdrant-docker-compose` for an example of how to set up Qdrant using Docker Compose.

If you use ddev, you can use the ddev addon from https://github.com/netz98/ddev-qdrant:

```
ddev get netz98/ddev-qdrant
ddev restart
```

use `qdrant` and port `6333` in both cases.

## Troubleshooting

### Common Issues

#### Collection Not Found Error
```
Collection 'test' doesn't exist!
```
**Solution**: The module now automatically creates collections during indexing. If you still see this error:
1. Check Qdrant server connectivity
2. Verify API key permissions
3. Ensure the collection name is valid

#### Connection Failed
```
HTTP request failed: Connection refused
```
**Solution**:
1. Verify Qdrant server is running
2. Check host/port configuration
3. Verify network connectivity between Drupal and Qdrant
4. Check firewall settings

#### Authentication Errors
```
HTTP request failed with code: 401
```
**Solution**:
1. Verify API key is correctly configured
2. Check that the key has necessary permissions
3. Ensure the key is not expired

### Debug Mode
Enable debug logging by setting log level to "Debug" in Drupal's logging configuration.
Check logs at `/admin/reports/dblog` for detailed error messages.

## Performance & Scaling

### Recommendations
- **Vector Dimensions**: Use appropriate dimensions for your use case (smaller = faster)
- **Collection Size**: Monitor collection size and implement archiving if needed
- **Batch Operations**: Index content in batches for better performance
- **Resource Allocation**: Ensure adequate memory on both Drupal and Qdrant servers

### Monitoring
- Monitor Qdrant server metrics
- Check Drupal logs for performance issues
- Use Qdrant's built-in monitoring tools

## Development

### Filter Implementation
The module converts SearchAPI filters to Qdrant's native JSON filter format. See `FILTER_IMPLEMENTATION.md` for technical details.

### Testing
Run the included unit tests:
```bash
ddev exec phpunit web/modules/custom/ai_vdb_provider_qdrant/tests/
```

### Contributing
1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Run tests and ensure they pass
5. Submit a pull request

## Notable Contributors
- `ai_vdb_provider_postgres` where i copied my homework from
- Claude Code with human review
- Factorial GmbH (sponsoring time and resources)
## License

This project is licensed under the GPL-2.0-or-later license - see the LICENSE file for details.

## Support

For issues and questions:
1. Check the troubleshooting section above
2. Review the logs at `/admin/reports/dblog`
3. Check the project issue queue
4. Review Qdrant documentation at https://qdrant.tech/documentation/
