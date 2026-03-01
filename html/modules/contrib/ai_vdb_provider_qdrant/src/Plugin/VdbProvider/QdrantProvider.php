<?php

namespace Drupal\ai_vdb_provider_qdrant\Plugin\VdbProvider;

use Drupal\ai\Attribute\AiVdbProvider;
use Drupal\ai\Base\AiVdbProviderClientBase;
use Drupal\ai\Enum\VdbSimilarityMetrics;
use Drupal\ai_search\EmbeddingStrategyInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\ai_vdb_provider_qdrant\Exception\CreateCollectionException;
use Drupal\ai_vdb_provider_qdrant\Exception\DatabaseNotConfiguredException;
use Drupal\ai_vdb_provider_qdrant\Exception\DeleteFromCollectionException;
use Drupal\ai_vdb_provider_qdrant\Exception\DropCollectionException;
use Drupal\ai_vdb_provider_qdrant\QdrantClient;

/**
 * Plugin implementation of the 'Qdrant vector DB' provider.
 */
#[AiVdbProvider(
    id: 'qdrant',
    label: new TranslatableMarkup(string: 'Qdrant vector DB'),
)]
class QdrantProvider extends AiVdbProviderClientBase implements ContainerFactoryPluginInterface {
  use StringTranslationTrait;
  // Use the LoggerChannelTrait for convenience since the parent class already
  // uses it and to avoid overriding the constructor just for logger injection.
  use LoggerChannelTrait;

  protected const LOGGER_CHANNEL = 'ai_vdb_provider_qdrant';

  protected const AI_SEARCH_NATIVE_FIELDS = [
    'drupal_entity_id',
    'drupal_long_id',
    'content',
    'vector',
    'server_id',
    'index_id',
  ];

  /**
   * {@inheritdoc}
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get(name: 'ai_vdb_provider_qdrant.settings');
  }

  /**
   * Get the Qdrant client connection.
   *
   * This connection is used to interface with the Qdrant client.
   *
   * @return mixed
   *   A connection to the Qdrant instance.
   *
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseConnectionException
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseNotConfiguredException
   */
  public function getConnection(string $database = 'default'): mixed {
    $config = $this->getConnectionData();
    return $this->getClient()->getConnection(
          host: $config['host'],
          port: $config['port'],
          api_key: $config['api_key']
      );
  }

  /**
   * Get connection data.
   *
   * @return array
   *   The connection data.
   *
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseNotConfiguredException
   */
  public function getConnectionData() {
    $config = $this->getConfig();
    $output['host'] = $this->configuration['host'] ?? $config->get(key: 'host');
    // Fail if host is not set.
    if (!$output['host']) {
      throw new DatabaseNotConfiguredException(message: 'Qdrant host is not configured');
    }

    $token = $config->get(key: 'api_key');
    $output['api_key'] = '';
    if ($token) {
      $key = $this->keyRepository->getKey(key_id: $token);
      if ($key) {
        $output['api_key'] = $key->getKeyValue();
      }
    }
    if (!empty($this->configuration['api_key'])) {
      $output['api_key'] = $this->configuration['api_key'];
    }

    $output['port'] = $this->configuration['port'] ?? $config->get(key: 'port');
    if (!$output['port']) {
      $output['port'] = '6333';
    }

    return $output;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseConnectionException
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseNotConfiguredException
   */
  public function ping(): bool {
    if ($connection = $this->getConnection()) {
      return $this->getClient()->ping(connection: $connection);
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function isSetup(): bool {
    if ($this->getConfig()->get(key: 'host')) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseConnectionException
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseNotConfiguredException
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\GetCollectionsException
   */
  public function getCollections(string $database = 'default'): array {
    return $this->getClient()->getCollections(
          connection: $this->getConnection(database: $database)
      );
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseConnectionException
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseNotConfiguredException
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\CreateCollectionException
   */
  public function createCollection(
    string $collection_name,
    int $dimension,
    VdbSimilarityMetrics $metric_type = VdbSimilarityMetrics::CosineSimilarity,
    string $database = 'default',
  ): void {
    try {
      $this->getClient()->createCollection(
            collection_name: $collection_name,
            dimension: $dimension,
            connection: $this->getConnection(database: $database)
        );
    }
    catch (CreateCollectionException $e) {
      // Do not throw error as this can happen in valid scenarios.
      // For example, if an index is cleared, an attempt is made to delete the
      // collection, even if the collection has not yet been created.
      $this->getLogger(self::LOGGER_CHANNEL)->warning(
            message: 'Create collection error: ' . $e->getMessage(),
            );
    }
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseConnectionException
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseNotConfiguredException
   */
  public function dropCollection(
    string $collection_name,
    string $database = 'default',
  ): void {
    try {
      $this->getClient()->dropCollection(
            collection_name: $collection_name,
            connection: $this->getConnection(database: $database)
        );
    }
    catch (DropCollectionException $e) {
      // Do not throw error as this can happen in valid scenarios.
      // For example, if an index is cleared, an attempt is made to delete the
      // collection, even if the collection has not yet been created.
      $this->getLogger(self::LOGGER_CHANNEL)->warning(
            message: 'Drop collection error: ' . $e->getMessage(),
            );
    }
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseConnectionException
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseNotConfiguredException
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\EscapeStringException
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\InsertIntoCollectionException
   */
  public function insertIntoCollection(
    string $collection_name,
    array $data,
    string $database = 'default',
  ): void {
    $nativeFieldValues = array_intersect_key($data, array_flip(self::AI_SEARCH_NATIVE_FIELDS));
    $extraFields = array_diff_key($data, array_flip(self::AI_SEARCH_NATIVE_FIELDS));
    $this->getClient()->insertIntoCollection(
          collection_name: $collection_name,
          drupal_entity_id: $nativeFieldValues['drupal_entity_id'],
          drupal_long_id: $nativeFieldValues['drupal_long_id'],
          content: $nativeFieldValues['content'],
          vector: $nativeFieldValues['vector'],
          server_id: $nativeFieldValues['server_id'],
          index_id: $nativeFieldValues['index_id'],
          extra_fields: $extraFields,
          connection: $this->getConnection($database),
      );
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseConnectionException
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseNotConfiguredException
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\EscapeStringException
   */
  public function deleteFromCollection(
    string $collection_name,
    array $ids,
    string $database = 'default',
  ): void {
    if (empty($ids)) {
      return;
    }
    try {
      $this->getClient()->deleteFromCollection(
            collection_name: $collection_name,
            ids: $ids,
            connection: $this->getConnection($database)
        );
    }
    catch (DeleteFromCollectionException $e) {
      // Do not throw error as this can happen in valid scenarios.
      // For example, if a node is saved, it is deleted from the index before
      // being re-added. Even if the node does not exist in the index.
      $this->getLogger(self::LOGGER_CHANNEL)->warning(
            message: 'Delete from collection error: ' . $e->getMessage(),
            );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(array $configuration, array $item_ids): void {
    if (empty($item_ids)) {
      return;
    }

    try {
      $vdbIds = $this->getVdbIds(
            collection_name: $configuration['database_settings']['collection'],
            drupalIds: $item_ids,
            database: $configuration['database_settings']['database_name'],
        );

      if ($vdbIds) {
        $this->deleteFromCollection(
          collection_name: $configuration['database_settings']['collection'],
          ids: $vdbIds,
          database: $configuration['database_settings']['database_name'],
          );
      }
    }
    catch (\Exception $e) {
      // Log the error but don't fail the entire operation.
      $this->getLogger(self::LOGGER_CHANNEL)->warning(
            'Failed to delete items from collection @collection: @message',
            ['@collection' => $configuration['database_settings']['collection'], '@message' => $e->getMessage()]
            );
    }
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseConnectionException
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseNotConfiguredException
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\EscapeStringException
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\QuerySearchException
   */
  public function querySearch(
    string $collection_name,
    array $output_fields,
    string|array $filters = '',
    int $limit = 10,
    int $offset = 0,
    string $database = 'default',
  ): array {
    // Convert filters to array format if needed.
    $filterArray = $this->convertFiltersToArray($filters);

    return $this->getClient()->querySearch(
          collection_name: $collection_name,
          output_fields: $output_fields,
          filters: $filterArray,
          limit: $limit,
          offset: $offset,
          connection: $this->getConnection($database)
      );
  }

  /**
   * {@inheritdoc}
   *
   * * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseConnectionException
   * * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseNotConfiguredException
   * * @throws \Drupal\ai_vdb_provider_qdrant\Exception\EscapeStringException
   * * @throws \Drupal\ai_vdb_provider_qdrant\Exception\VectorSearchException.
   */
  public function vectorSearch(
    string $collection_name,
    array $vector_input,
    array $output_fields,
    QueryInterface $query,
    string|array $filters = '',
    int $limit = 10,
    int $offset = 0,
    string $database = 'default',
  ): array {
    $metric_type = VdbSimilarityMetrics::from(
          $query->getIndex()->getServerInstance()->getBackendConfig()['database_settings']['metric']
      );
    // Convert filters to array format if needed.
    $filterArray = $this->convertFiltersToArray($filters);

    return $this->getClient()->vectorSearch(
          collection_name: $collection_name,
          vector_input: $vector_input,
          output_fields: $output_fields,
          filters: $filterArray,
          limit: $limit,
          offset: $offset,
          metric_type: $metric_type,
          connection: $this->getConnection($database)
      );
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseConnectionException
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\DatabaseNotConfiguredException
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\EscapeStringException
   * @throws \Drupal\ai_vdb_provider_qdrant\Exception\QuerySearchException
   */
  public function getVdbIds(
    string $collection_name,
    array $drupalIds,
    string $database = 'default',
  ): array {
    if (empty($drupalIds)) {
      return [];
    }

    try {
      // Check if collection exists before querying.
      $collections = $this->getCollections($database);
      if (!in_array($collection_name, $collections)) {
        // Collection doesn't exist, return empty array.
        return [];
      }

      // Create Qdrant filter for drupal_entity_id IN (drupalIds)
      $filters = [
        'must' => [
            [
              'key' => 'drupal_entity_id',
              'match' => ['any' => $drupalIds],
            ],
        ],
      ];

      $data = $this->getClient()->querySearch(
            collection_name: $collection_name,
            output_fields: ['id'],
            filters: $filters,
            limit: 1000,
            offset: 0,
            connection: $this->getConnection($database)
        );

      $ids = [];
      if (!empty($data)) {
        foreach ($data as $item) {
          $ids[] = $item['id'];
        }
      }
      return $ids;
    }
    catch (\Exception $e) {
      // Log the error and return empty array to allow indexing to continue.
      $this->getLogger(self::LOGGER_CHANNEL)->warning(
            'Failed to get VDB IDs for collection @collection: @message',
            ['@collection' => $collection_name, '@message' => $e->getMessage()]
            );
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getRawEmbeddingFieldName(): ?string {
    return 'vector';
  }

  /**
   * {@inheritDoc}
   */
  public function getClient(): QdrantClient {
    return \Drupal::service('ai_vdb_provider_qdrant.client');
  }

  /**
   * {@inheritDoc}
   */
  public function prepareFilters(QueryInterface $query): mixed {
    try {
      $condition_group = $query->getConditionGroup();
      $filters = $this->convertConditionGroupToQdrant($query->getIndex(), $condition_group);

      // Validate the generated filter structure.
      if (!empty($filters)) {
        $this->validateQdrantFilter($filters);
      }

      return $filters;
    }
    catch (\Exception $e) {
      $this->getLogger(self::LOGGER_CHANNEL)->error(
            'Failed to prepare filters: @message',
            ['@message' => $e->getMessage()]
            );
      // Return empty filter on error to allow the query to continue.
      return [];
    }
  }

  /**
   * Convert SearchAPI condition group to Qdrant filter format.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The Search API Index.
   * @param \Drupal\search_api\Query\ConditionGroupInterface $condition_group
   *   The condition group.
   *
   * @return array
   *   The Qdrant filter array.
   */
  protected function convertConditionGroupToQdrant(IndexInterface $index, ConditionGroupInterface $condition_group): array {
    $conjunction = $condition_group->getConjunction();
    $filters = [];

    foreach ($condition_group->getConditions() as $condition) {
      // Check if the current condition is actually a nested ConditionGroup.
      if ($condition instanceof ConditionGroupInterface) {
        // Recursively process the nested ConditionGroup.
        $nestedFilter = $this->convertConditionGroupToQdrant($index, $condition);
        if (!empty($nestedFilter)) {
          $filters[] = $nestedFilter;
        }
        continue;
      }

      // Convert individual condition to Qdrant filter.
      $qdrantCondition = $this->convertConditionToQdrant($index, $condition);
      if (!empty($qdrantCondition)) {
        $filters[] = $qdrantCondition;
      }
    }

    // Map SearchAPI conjunction to Qdrant filter clauses.
    return $this->mapConjunctionToQdrant($conjunction, $filters);
  }

  /**
   * Convert a single SearchAPI condition to Qdrant filter format.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The Search API Index.
   * @param \Drupal\search_api\Query\ConditionInterface $condition
   *   The condition to convert.
   *
   * @return array
   *   The Qdrant filter condition.
   */
  protected function convertConditionToQdrant(IndexInterface $index, $condition): array {
    $field = $condition->getField();
    $operator = $condition->getOperator();
    $value = $condition->getValue();

    // Get field information.
    $fieldData = $index->getField($field);
    $fieldType = 'string';
    $isMultiple = FALSE;

    if ($fieldData) {
      $fieldType = $fieldData->getType();
      $isMultiple = $this->isMultiple($fieldData);
    }
    elseif (!in_array($field, self::AI_SEARCH_NATIVE_FIELDS)) {
      // If the field is not indexed, log a warning and skip.
      $this->messenger->addWarning('Field @field is not indexed on the @index so cannot be filtered on.', [
        '@field' => $field,
        '@index' => $index->id(),
      ]);
      return [];
    }

    // Convert value based on field type.
    $convertedValue = $this->convertValueByType($value, $fieldType);

    // Handle multi-value fields.
    if ($isMultiple && is_array($convertedValue)) {
      return $this->handleMultiValueField($field, $convertedValue, $operator);
    }

    // Convert single value condition.
    return $this->createQdrantCondition($field, $operator, $convertedValue);
  }

  /**
   * Create a Qdrant condition based on operator and value.
   *
   * @param string $field
   *   The field name.
   * @param string $operator
   *   The SearchAPI operator.
   * @param mixed $value
   *   The converted value.
   *
   * @return array
   *   The Qdrant condition array.
   */
  protected function createQdrantCondition(string $field, string $operator, $value): array {
    switch ($operator) {
      case '=':
        return ['key' => $field, 'match' => ['value' => $value]];

      case '<>':
      case '!=':
        return ['negative' => TRUE, 'condition' => ['key' => $field, 'match' => ['value' => $value]]];

      case '<':
        return ['key' => $field, 'range' => ['lt' => $value]];

      case '<=':
        return ['key' => $field, 'range' => ['lte' => $value]];

      case '>':
        return ['key' => $field, 'range' => ['gt' => $value]];

      case '>=':
        return ['key' => $field, 'range' => ['gte' => $value]];

      case 'IN':
        return ['key' => $field, 'match' => ['any' => is_array($value) ? $value : [$value]]];

      case 'NOT IN':
        return ['negative' => TRUE, 'condition' => ['key' => $field, 'match' => ['any' => is_array($value) ? $value : [$value]]]];

      case 'BETWEEN':
        if (is_array($value) && count($value) === 2) {
          return ['key' => $field, 'range' => ['gte' => $value[0], 'lte' => $value[1]]];
        }
        break;

      case 'NOT BETWEEN':
        if (is_array($value) && count($value) === 2) {
          return ['negative' => TRUE, 'condition' => ['key' => $field, 'range' => ['gte' => $value[0], 'lte' => $value[1]]]];
        }
        break;

      default:
        $this->messenger->addWarning('Operator @operator is not supported by the Qdrant integration.', [
          '@operator' => $operator,
        ]);
        return [];
    }

    return [];
  }

  /**
   * Handle multi-value field filtering.
   *
   * @param string $field
   *   The field name.
   * @param array $values
   *   The values to filter on.
   * @param string $operator
   *   The SearchAPI operator.
   *
   * @return array
   *   The Qdrant condition array.
   */
  protected function handleMultiValueField(string $field, array $values, string $operator): array {
    switch ($operator) {
      case 'IN':
        return ['key' => $field, 'match' => ['any' => $values]];

      case 'NOT IN':
        return ['negative' => TRUE, 'condition' => ['key' => $field, 'match' => ['any' => $values]]];

      case '=':
        // For multi-value fields, = means contains.
        return ['key' => $field, 'match' => ['value' => $values[0]]];

      case '!=':
        return ['negative' => TRUE, 'condition' => ['key' => $field, 'match' => ['value' => $values[0]]]];

      default:
        // For other operators, use the first value.
        return $this->createQdrantCondition($field, $operator, $values[0]);
    }
  }

  /**
   * Convert value based on field type.
   *
   * @param mixed $value
   *   The original value.
   * @param string $fieldType
   *   The field type.
   *
   * @return mixed
   *   The converted value.
   */
  protected function convertValueByType($value, string $fieldType) {
    switch ($fieldType) {
      case 'date':
      case 'datetime':
        // Convert to ISO 8601 format for Qdrant.
        if (is_numeric($value)) {
          return date('c', $value);
        }
        return $value;

      case 'boolean':
        return (bool) $value;

      case 'integer':
        return (int) $value;

      case 'decimal':
      case 'float':
        return (float) $value;

      case 'string':
      case 'text':
      case 'full_text':
      default:
        return $value;
    }
  }

  /**
   * Map SearchAPI conjunction to Qdrant filter clauses.
   *
   * @param string $conjunction
   *   The SearchAPI conjunction (AND/OR).
   * @param array $filters
   *   The filter conditions.
   *
   * @return array
   *   The Qdrant filter structure.
   */
  protected function mapConjunctionToQdrant(string $conjunction, array $filters): array {
    if (empty($filters)) {
      return [];
    }

    // Separate positive and negative conditions.
    $positive_conditions = [];
    $negative_conditions = [];

    foreach ($filters as $filter) {
      if (isset($filter['negative']) && $filter['negative']) {
        $negative_conditions[] = $filter['condition'];
      }
      else {
        $positive_conditions[] = $filter;
      }
    }

    $result = [];

    switch (strtoupper($conjunction)) {
      case 'AND':
        if ($positive_conditions) {
          $result['must'] = $positive_conditions;
        }
        if ($negative_conditions) {
          $result['must_not'] = $negative_conditions;
        }
        break;

      case 'OR':
        if ($positive_conditions) {
          $result['should'] = $positive_conditions;
        }
        if ($negative_conditions) {
          // For OR with negatives, we need to negate the entire group.
          $result['must_not'] = [
            'must' => $negative_conditions,
          ];
        }
        break;
    }

    return $result;
  }

  /**
   * Validate that the generated Qdrant filter structure is valid.
   *
   * @param array $filter
   *   The filter array to validate.
   *
   * @throws \InvalidArgumentException
   *   If the filter structure is invalid.
   */
  protected function validateQdrantFilter(array $filter): void {
    // Check for valid top-level keys.
    $validKeys = ['must', 'should', 'must_not'];
    $filterKeys = array_keys($filter);

    foreach ($filterKeys as $key) {
      if (!in_array($key, $validKeys)) {
        throw new \InvalidArgumentException("Invalid filter key: $key. Must be one of: " . implode(', ', $validKeys));
      }
    }

    // Validate each condition group.
    foreach ($filter as $key => $conditions) {
      if (!is_array($conditions)) {
        throw new \InvalidArgumentException("Filter $key must be an array of conditions");
      }

      foreach ($conditions as $condition) {
        $this->validateQdrantCondition($condition);
      }
    }
  }

  /**
   * Validate a single Qdrant condition.
   *
   * @param array $condition
   *   The condition to validate.
   *
   * @throws \InvalidArgumentException
   *   If the condition is invalid.
   */
  protected function validateQdrantCondition(array $condition): void {
    if (!isset($condition['key'])) {
      throw new \InvalidArgumentException('Qdrant condition must have a "key" field');
    }

    if (!isset($condition['match']) && !isset($condition['range'])) {
      throw new \InvalidArgumentException('Qdrant condition must have either "match" or "range" field');
    }

    // Validate match conditions.
    if (isset($condition['match'])) {
      $match = $condition['match'];
      if (!isset($match['value']) && !isset($match['any'])) {
        throw new \InvalidArgumentException('Qdrant match condition must have either "value" or "any" field');
      }
    }

    // Validate range conditions.
    if (isset($condition['range'])) {
      $range = $condition['range'];
      $validRangeKeys = ['gt', 'gte', 'lt', 'lte'];
      $rangeKeys = array_keys($range);

      if (empty($rangeKeys)) {
        throw new \InvalidArgumentException('Qdrant range condition must have at least one range operator');
      }

      foreach ($rangeKeys as $key) {
        if (!in_array($key, $validRangeKeys)) {
          throw new \InvalidArgumentException("Invalid range key: $key. Must be one of: " . implode(', ', $validRangeKeys));
        }
      }
    }
  }

  /**
   * Convert filters from string/mixed to array format.
   *
   * @param mixed $filters
   *   The filters (could be string from interface or array from prepareFilters).
   *
   * @return array
   *   The filters in array format.
   */
  protected function convertFiltersToArray($filters): array {
    if (is_array($filters)) {
      return $filters;
    }

    if (is_string($filters) && !empty($filters)) {
      // Try to decode JSON if it's a string.
      $decoded = json_decode($filters, TRUE);
      if (json_last_error() === JSON_ERROR_NONE) {
        return $decoded;
      }
    }

    return [];
  }

  /**
   * Ensure collection exists before operations.
   *
   * @param string $collection_name
   *   The collection name.
   * @param int $dimension
   *   The vector dimension.
   * @param string $database
   *   The database name.
   */
  protected function ensureCollectionExists(string $collection_name, int $dimension, string $database = 'default'): void {
    try {
      $collections = $this->getCollections($database);
      if (!in_array($collection_name, $collections)) {
        $this->createCollection(
              collection_name: $collection_name,
              dimension: $dimension,
              database: $database
          );
      }
    }
    catch (\Exception $e) {
      $this->getLogger(self::LOGGER_CHANNEL)->warning(
            'Failed to ensure collection @collection exists: @message',
            ['@collection' => $collection_name, '@message' => $e->getMessage()]
            );
    }
  }

  /**
   * Check if a field is multiple-valued.
   *
   * @param \Drupal\search_api\Item\FieldInterface $field
   *   The field to check.
   *
   * @return bool
   *   TRUE if the field is multiple-valued, FALSE otherwise.
   */
  public function isMultiple($field): bool {
    return ai_vdb_provider_qdrant_is_field_multiple($field);
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(
    array $configuration,
    IndexInterface $index,
    array $items,
    EmbeddingStrategyInterface $embedding_strategy,
  ): array {
    $successfulItemIds = [];
    $itemBase = [
      'metadata' => [
        'server_id' => $index->getServerId(),
        'index_id' => $index->id(),
      ],
    ];

    // Ensure collection exists before any operations.
    $dimension = $configuration['embedding_strategy_configuration']['dimensions'] ?? 1536;
    $this->ensureCollectionExists(
          collection_name: $configuration['database_settings']['collection'],
          dimension: $dimension,
          database: $configuration['database_settings']['database_name']
      );

    // Check if we need to delete some items first.
    $this->deleteItems($configuration, array_values(array_map(function ($item) {
        return $item->getId();
    }, $items)));

    /** @var \Drupal\search_api\Item\ItemInterface $item */
    foreach ($items as $item) {
      $fields = $item->getFields();
      $embeddings = $embedding_strategy->getEmbedding(
            $configuration['embeddings_engine'],
            $configuration['chat_model'],
            $configuration['embedding_strategy_configuration'],
            $fields,
            $item,
            $index,
        );
      foreach ($embeddings as $embedding) {
        // Ensure consistent embedding structure as per
        // EmbeddingStrategyInterface.
        $this->validateRetrievedEmbedding($embedding);

        // Merge the base array structure with the individual chunk array
        // structure and add additional details.
        $embedding = array_merge_recursive($embedding, $itemBase);
        $data['drupal_long_id'] = ['value' => $embedding['id'], 'is_multiple' => FALSE];
        $data['drupal_entity_id'] = ['value' => $item->getId(), 'is_multiple' => FALSE];
        $data['vector'] = ['value' => $embedding['values'], 'is_multiple' => FALSE];
        foreach ($embedding['metadata'] as $key => $value) {
          if (in_array($key, self::AI_SEARCH_NATIVE_FIELDS)) {
            $data[$key] = ['value' => $value, 'is_multiple' => FALSE];
            continue;
          }
          $data[$key] = ['value' => $value, 'is_multiple' => ai_vdb_provider_qdrant_is_field_multiple($fields[$key])];
        }
        $this->insertIntoCollection(
          collection_name: $configuration['database_settings']['collection'],
          data: $data,
          database: $configuration['database_settings']['database_name'],
          );
      }

      $successfulItemIds[] = $item->getId();
    }
    return $successfulItemIds;
  }

}
