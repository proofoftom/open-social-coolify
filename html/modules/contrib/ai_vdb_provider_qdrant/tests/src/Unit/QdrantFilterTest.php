<?php

namespace Drupal\Tests\ai_vdb_provider_qdrant\Unit;

use Drupal\ai_vdb_provider_qdrant\Plugin\VdbProvider\QdrantProvider;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Query\ConditionInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests for Qdrant filter conversion functionality.
 *
 * @group ai_vdb_provider_qdrant
 */
class QdrantFilterTest extends UnitTestCase {

  /**
   * The Qdrant provider instance.
   *
   * @var \Drupal\ai_vdb_provider_qdrant\Plugin\VdbProvider\QdrantProvider
   */
  protected $provider;

  /**
   * Mock SearchAPI index.
   *
   * @var \Drupal\search_api\IndexInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $index;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create mock index.
    $this->index = $this->createMock(IndexInterface::class);

    // Create provider instance (this would need proper setup in real tests)
    $this->provider = $this->getMockBuilder(QdrantProvider::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getLogger', 'messenger'])
      ->getMock();
  }

  /**
   * Test simple equality condition conversion.
   */
  public function testSimpleEqualityCondition(): void {
    $condition = $this->createMock(ConditionInterface::class);
    $condition->method('getField')->willReturn('title');
    $condition->method('getOperator')->willReturn('=');
    $condition->method('getValue')->willReturn('test value');

    $field = $this->createMock(FieldInterface::class);
    $field->method('getType')->willReturn('string');

    $this->index->method('getField')->with('title')->willReturn($field);

    // Use reflection to test protected method.
    $reflection = new \ReflectionClass($this->provider);
    $method = $reflection->getMethod('convertConditionToQdrant');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->provider, $this->index, $condition);

    $expected = [
      'key' => 'title',
      'match' => ['value' => 'test value'],
    ];

    $this->assertEquals($expected, $result);
  }

  /**
   * Test range condition conversion.
   */
  public function testRangeCondition(): void {
    $condition = $this->createMock(ConditionInterface::class);
    $condition->method('getField')->willReturn('price');
    $condition->method('getOperator')->willReturn('>=');
    $condition->method('getValue')->willReturn(100);

    $field = $this->createMock(FieldInterface::class);
    $field->method('getType')->willReturn('integer');

    $this->index->method('getField')->with('price')->willReturn($field);

    // Use reflection to test protected method.
    $reflection = new \ReflectionClass($this->provider);
    $method = $reflection->getMethod('convertConditionToQdrant');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->provider, $this->index, $condition);

    $expected = [
      'key' => 'price',
      'range' => ['gte' => 100],
    ];

    $this->assertEquals($expected, $result);
  }

  /**
   * Test IN condition conversion.
   */
  public function testInCondition(): void {
    $condition = $this->createMock(ConditionInterface::class);
    $condition->method('getField')->willReturn('category');
    $condition->method('getOperator')->willReturn('IN');
    $condition->method('getValue')->willReturn(['news', 'blog']);

    $field = $this->createMock(FieldInterface::class);
    $field->method('getType')->willReturn('string');

    $this->index->method('getField')->with('category')->willReturn($field);

    // Use reflection to test protected method.
    $reflection = new \ReflectionClass($this->provider);
    $method = $reflection->getMethod('convertConditionToQdrant');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->provider, $this->index, $condition);

    $expected = [
      'key' => 'category',
      'match' => ['any' => ['news', 'blog']],
    ];

    $this->assertEquals($expected, $result);
  }

  /**
   * Test negative condition conversion.
   */
  public function testNegativeCondition(): void {
    $condition = $this->createMock(ConditionInterface::class);
    $condition->method('getField')->willReturn('status');
    $condition->method('getOperator')->willReturn('!=');
    $condition->method('getValue')->willReturn('draft');

    $field = $this->createMock(FieldInterface::class);
    $field->method('getType')->willReturn('string');

    $this->index->method('getField')->with('status')->willReturn($field);

    // Use reflection to test protected method.
    $reflection = new \ReflectionClass($this->provider);
    $method = $reflection->getMethod('convertConditionToQdrant');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->provider, $this->index, $condition);

    $expected = [
      'negative' => TRUE,
      'condition' => [
        'key' => 'status',
        'match' => ['value' => 'draft'],
      ],
    ];

    $this->assertEquals($expected, $result);
  }

  /**
   * Test AND conjunction mapping.
   */
  public function testAndConjunction(): void {
    $filters = [
      ['key' => 'title', 'match' => ['value' => 'test']],
      ['key' => 'status', 'match' => ['value' => 'published']],
    ];

    // Use reflection to test protected method.
    $reflection = new \ReflectionClass($this->provider);
    $method = $reflection->getMethod('mapConjunctionToQdrant');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->provider, 'AND', $filters);

    $expected = [
      'must' => [
        ['key' => 'title', 'match' => ['value' => 'test']],
        ['key' => 'status', 'match' => ['value' => 'published']],
      ],
    ];

    $this->assertEquals($expected, $result);
  }

  /**
   * Test OR conjunction mapping.
   */
  public function testOrConjunction(): void {
    $filters = [
      ['key' => 'title', 'match' => ['value' => 'test']],
      ['key' => 'body', 'match' => ['value' => 'example']],
    ];

    // Use reflection to test protected method.
    $reflection = new \ReflectionClass($this->provider);
    $method = $reflection->getMethod('mapConjunctionToQdrant');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->provider, 'OR', $filters);

    $expected = [
      'should' => [
        ['key' => 'title', 'match' => ['value' => 'test']],
        ['key' => 'body', 'match' => ['value' => 'example']],
      ],
    ];

    $this->assertEquals($expected, $result);
  }

  /**
   * Test filter validation.
   */
  public function testFilterValidation(): void {
    $validFilter = [
      'must' => [
        ['key' => 'title', 'match' => ['value' => 'test']],
      ],
    ];

    // Use reflection to test protected method.
    $reflection = new \ReflectionClass($this->provider);
    $method = $reflection->getMethod('validateQdrantFilter');
    $method->setAccessible(TRUE);

    // Should not throw an exception.
    $method->invoke($this->provider, $validFilter);

    // Test invalid filter.
    $invalidFilter = [
      'invalid_key' => [
        ['key' => 'title', 'match' => ['value' => 'test']],
      ],
    ];

    $this->expectException(\InvalidArgumentException::class);
    $method->invoke($this->provider, $invalidFilter);
  }

}
