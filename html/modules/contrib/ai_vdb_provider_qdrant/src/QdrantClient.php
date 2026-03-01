<?php

namespace Drupal\ai_vdb_provider_qdrant;

use Drupal\ai\Enum\VdbSimilarityMetrics;
use Drupal\Core\Http\ClientFactory;
use GuzzleHttp\Exception\RequestException;
use Drupal\ai_vdb_provider_qdrant\Exception\CreateCollectionException;
use Drupal\ai_vdb_provider_qdrant\Exception\DeleteFromCollectionException;
use Drupal\ai_vdb_provider_qdrant\Exception\DropCollectionException;
use Drupal\ai_vdb_provider_qdrant\Exception\GetCollectionsException;
use Drupal\ai_vdb_provider_qdrant\Exception\InsertIntoCollectionException;
use Drupal\ai_vdb_provider_qdrant\Exception\QuerySearchException;
use Drupal\ai_vdb_provider_qdrant\Exception\VectorSearchException;

/**
 * Provides abstracted Qdrant client to interface with Qdrant HTTP API.
 */
class QdrantClient {

  /**
   * The base URL for the Qdrant server.
   */
  private string $baseUrl;

  /**
   * The API key for authentication.
   */
  private string $apiKey;

  /**
   * The HTTP client for making requests.
   */
  private $httpClient;

  /**
   * Constructs a QdrantClient object.
   *
   * @param \Drupal\Core\Http\ClientFactory $http_client_factory
   *   The HTTP client factory for making requests.
   */
  public function __construct(ClientFactory $http_client_factory) {
    $this->httpClient = $http_client_factory->fromOptions([
      'headers' => ['Content-Type' => 'application/json'],
    ]);
  }

  /**
   * Get the Qdrant client connection configuration.
   *
   * @param string $host
   *   The Qdrant server host.
   * @param int $port
   *   The Qdrant server port.
   * @param string $api_key
   *   Optional API key for authentication.
   * @param string $database
   *   The database name (default: 'default'). Currently unused by Qdrant.
   *
   * @return array
   *   Configuration array for Qdrant connection.
   *
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseConnectionException
   */
  public function getConnection(
    string $host,
    int $port,
    string $api_key = '',
    string $database = 'default',
  ): array {
    // Ensure host has proper protocol.
    if (!str_starts_with($host, 'http://') && !str_starts_with($host, 'https://')) {
      $host = 'http://' . $host;
    }

    $this->baseUrl = rtrim($host, '/') . ':' . $port;
    $this->apiKey = $api_key;

    return [
      'base_url' => $this->baseUrl,
      'api_key' => $this->apiKey,
    ];
  }

  /**
   * Test connection to Qdrant server.
   */
  public function ping(array $connection): bool {
    try {
      $response = $this->makeHttpRequest('GET', '/');
      return $response !== FALSE;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Get all collections from Qdrant.
   *
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\GetCollectionsException
   */
  public function getCollections(array $connection): array {
    try {
      $response = $this->makeHttpRequest('GET', '/collections');
      $data = json_decode($response, TRUE);

      if (isset($data['result']['collections'])) {
        return array_column($data['result']['collections'], 'name');
      }

      return [];
    }
    catch (\Exception $e) {
      throw new GetCollectionsException('Failed to get collections: ' . $e->getMessage());
    }
  }

  /**
   * Create a collection in Qdrant.
   *
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\CreateCollectionException
   */
  public function createCollection(
    string $collection_name,
    int $dimension,
    array $connection,
  ): void {
    $payload = [
      'vectors' => [
        'size' => $dimension,
        'distance' => 'Cosine',
      ],
    ];

    try {
      $response = $this->makeHttpRequest(
        'PUT',
        "/collections/{$collection_name}",
        $payload
      );

      $data = json_decode($response, TRUE);
      if (!isset($data['result']) || !$data['result']) {
        throw new CreateCollectionException('Failed to create collection: ' . ($data['status']['error'] ?? 'Unknown error'));
      }
    }
    catch (\Exception $e) {
      throw new CreateCollectionException('Failed to create collection: ' . $e->getMessage());
    }
  }

  /**
   * Drop a collection from Qdrant.
   *
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DropCollectionException
   */
  public function dropCollection(
    string $collection_name,
    array $connection,
  ): void {
    try {
      $response = $this->makeHttpRequest('DELETE', "/collections/{$collection_name}");
      $data = json_decode($response, TRUE);

      if (!isset($data['result']) || !$data['result']) {
        throw new DropCollectionException('Failed to drop collection: ' . ($data['status']['error'] ?? 'Unknown error'));
      }
    }
    catch (\Exception $e) {
      throw new DropCollectionException('Failed to drop collection: ' . $e->getMessage());
    }
  }

  /**
   * Insert points into a Qdrant collection.
   *
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\InsertIntoCollectionException
   */
  public function insertIntoCollection(
    string $collection_name,
    array $drupal_entity_id,
    array $drupal_long_id,
    array $content,
    array $vector,
    array $server_id,
    array $index_id,
    array $extra_fields,
    array $connection,
  ): void {
    // Generate a unique ID for the point.
    $point_id = md5($drupal_long_id['value']);

    // Prepare payload for Qdrant.
    $payload = [
      'id' => $point_id,
      'vector' => $vector['value'],
      'payload' => [
        'drupal_entity_id' => $drupal_entity_id['value'],
        'drupal_long_id' => $drupal_long_id['value'],
        'content' => $content['value'],
        'server_id' => $server_id['value'],
        'index_id' => $index_id['value'],
      ],
    ];

    // Add extra fields to payload.
    foreach ($extra_fields as $field_name => $field_data) {
      $payload['payload'][$field_name] = $field_data['value'];
    }

    try {
      $response = $this->makeHttpRequest(
        'PUT',
        "/collections/{$collection_name}/points",
        ['points' => [$payload]]
      );

      $data = json_decode($response, TRUE);
      if (!isset($data['result']) || $data['result']['status'] !== 'acknowledged') {
        throw new InsertIntoCollectionException('Failed to insert into collection: ' . ($data['status']['error'] ?? 'Unknown error'));
      }
    }
    catch (\Exception $e) {
      throw new InsertIntoCollectionException('Failed to insert into collection: ' . $e->getMessage());
    }
  }

  /**
   * Delete points from a Qdrant collection.
   *
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DeleteFromCollectionException
   */
  public function deleteFromCollection(
    string $collection_name,
    array $ids,
    array $connection,
  ): void {
    if (empty($ids)) {
      return;
    }

    $payload = [
      'points' => array_values($ids),
    ];

    try {
      $response = $this->makeHttpRequest(
        'POST',
        "/collections/{$collection_name}/points/delete",
        $payload
      );

      $data = json_decode($response, TRUE);
      if (!isset($data['result']) || $data['result']['status'] !== 'acknowledged') {
        throw new DeleteFromCollectionException('Failed to delete from collection: ' . ($data['status']['error'] ?? 'Unknown error'));
      }
    }
    catch (\Exception $e) {
      throw new DeleteFromCollectionException('Failed to delete from collection: ' . $e->getMessage());
    }
  }

  /**
   * Search/query points in a Qdrant collection.
   *
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\QuerySearchException
   */
  public function querySearch(
    string $collection_name,
    array $output_fields,
    array $filters,
    int $limit,
    int $offset,
    array $connection,
  ): array {
    $payload = [
      'limit' => $limit,
      'offset' => $offset,
      'with_payload' => TRUE,
      'with_vector' => FALSE,
    ];

    // Add filters if provided.
    if (!empty($filters)) {
      $payload['filter'] = $filters;
    }

    try {
      $response = $this->makeHttpRequest(
        'POST',
        "/collections/{$collection_name}/points/scroll",
        $payload
      );

      $data = json_decode($response, TRUE);
      if (!isset($data['result']['points'])) {
        throw new QuerySearchException('Failed to query search: ' . ($data['status']['error'] ?? 'No points returned'));
      }

      return $this->formatPointsResponse($data['result']['points'], $output_fields);
    }
    catch (\Exception $e) {
      throw new QuerySearchException('Failed to query search: ' . $e->getMessage());
    }
  }

  /**
   * Perform vector search in a Qdrant collection.
   *
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\VectorSearchException
   */
  public function vectorSearch(
    string $collection_name,
    array $vector_input,
    array $output_fields,
    array $filters,
    int $limit,
    int $offset,
    VdbSimilarityMetrics $metric_type,
    array $connection,
  ): array {
    $payload = [
      'vector' => $vector_input,
      'limit' => $limit,
      'offset' => $offset,
      'with_payload' => TRUE,
      'with_vector' => FALSE,
    ];

    // Add filters if provided.
    if (!empty($filters)) {
      $payload['filter'] = $filters;
    }

    try {
      $response = $this->makeHttpRequest(
        'POST',
        "/collections/{$collection_name}/points/search",
        $payload
      );

      $data = json_decode($response, TRUE);
      if (!isset($data['result'])) {
        throw new VectorSearchException('Failed to vector search: ' . ($data['status']['error'] ?? 'No results returned'));
      }

      return $this->formatSearchResponse($data['result'], $output_fields);
    }
    catch (\Exception $e) {
      throw new VectorSearchException('Failed to vector search: ' . $e->getMessage());
    }
  }

  /**
   * Update fields (Qdrant doesn't require explicit field updates).
   */
  public function updateFields($fields, string $collection_name, array $connection): void {
    // Qdrant is schemaless, so this is a no-op
    // Fields are automatically handled when inserting points.
  }

  /**
   * Helper method to make HTTP requests to Qdrant.
   */
  private function makeHttpRequest(string $method, string $endpoint, ?array $data = NULL): string {
    $url = $this->baseUrl . $endpoint;
    $options = [
      'headers' => [
        'Content-Type' => 'application/json',
      ],
    ];

    // Add API key if available.
    if (!empty($this->apiKey)) {
      $options['headers']['Api-Key'] = $this->apiKey;
    }

    if ($data !== NULL) {
      $options['json'] = $data;
    }

    try {
      $response = $this->httpClient->request($method, $url, $options);
      $responseBody = $response->getBody()->getContents();

      if ($response->getStatusCode() >= 400) {
        throw new \Exception("HTTP request failed with code: " . $response->getStatusCode() . ", body: " . $responseBody);
      }

      return $responseBody;
    }
    catch (RequestException $e) {
      throw new \Exception("HTTP request failed: " . $e->getMessage());
    }
  }

  /**
   * Format Qdrant points response to match expected format.
   */
  private function formatPointsResponse(array $points, array $output_fields): array {
    $result = [];

    foreach ($points as $point) {
      $row = [];
      foreach ($output_fields as $field) {
        if ($field === 'id') {
          $row['id'] = $point['id'];
        }
        elseif (isset($point['payload'][$field])) {
          $row[$field] = $point['payload'][$field];
        }
      }
      $result[] = $row;
    }

    return $result;
  }

  /**
   * Format Qdrant search response to match expected format.
   */
  private function formatSearchResponse(array $results, array $output_fields): array {
    $formatted = [];

    foreach ($results as $result) {
      $row = ['distance' => $result['score']];

      foreach ($output_fields as $field) {
        if ($field === 'id') {
          $row['id'] = $result['id'];
        }
        elseif (isset($result['payload'][$field])) {
          $row[$field] = $result['payload'][$field];
        }
      }

      $formatted[] = $row;
    }

    return $formatted;
  }

}
