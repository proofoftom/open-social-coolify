<?php

declare(strict_types=1);

namespace Drupal\Tests\gemini_provider\Unit\Plugin\AiProvider;

use Drupal\gemini_provider\Plugin\AiProvider\GeminiProvider;
use Gemini\Data\DataFormat;
use Gemini\Data\Schema;
use Gemini\Enums\DataType;
use PHPUnit\Framework\TestCase;

/**
 * Tests GeminiProvider JSON schema to Gemini Schema conversion.
 *
 * These methods convert OpenAI-style JSON schemas (used by the AI module)
 * into Gemini's native Schema objects. Bugs here cause silent data
 * corruption — Gemini receives a malformed schema and returns garbage.
 *
 * @coversDefaultClass \Drupal\gemini_provider\Plugin\AiProvider\GeminiProvider
 * @group gemini_provider
 */
class GeminiProviderSchemaTest extends TestCase {

  /**
   * The provider instance created without constructor.
   *
   * @var \Drupal\gemini_provider\Plugin\AiProvider\GeminiProvider
   */
  protected GeminiProvider $provider;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $reflection = new \ReflectionClass(GeminiProvider::class);
    $this->provider = $reflection->newInstanceWithoutConstructor();
  }

  /**
   * Invokes a private/protected method on the provider.
   *
   * @param string $method_name
   *   The method name.
   * @param array $args
   *   The arguments to pass.
   *
   * @return mixed
   *   The return value.
   */
  private function invokeMethod(string $method_name, array $args): mixed {
    $method = new \ReflectionMethod(GeminiProvider::class, $method_name);
    return $method->invoke($this->provider, ...$args);
  }

  /**
   * Tests converting a simple string schema.
   *
   * @covers ::convertJsonSchemaToGeminiSchema
   * @covers ::buildGeminiSchemaFromArray
   */
  public function testConvertSimpleString(): void {
    $schema = $this->invokeMethod('convertJsonSchemaToGeminiSchema', [
      ['schema' => ['type' => 'string']],
    ]);

    $this->assertInstanceOf(Schema::class, $schema);
    $this->assertSame(DataType::STRING, $schema->type);
  }

  /**
   * Tests converting an object schema with properties and required fields.
   *
   * This is the most common case — structured output like:
   * {"name": "John", "age": 30}
   *
   * @covers ::convertJsonSchemaToGeminiSchema
   * @covers ::buildGeminiSchemaFromArray
   */
  public function testConvertObjectWithProperties(): void {
    $schema = $this->invokeMethod('convertJsonSchemaToGeminiSchema', [
      [
        'name' => 'person',
        'schema' => [
          'type' => 'object',
          'properties' => [
            'name' => ['type' => 'string', 'description' => 'Full name'],
            'age' => ['type' => 'integer'],
          ],
          'required' => ['name', 'age'],
        ],
      ],
    ]);

    $this->assertSame(DataType::OBJECT, $schema->type);
    $this->assertCount(2, $schema->properties);
    $this->assertArrayHasKey('name', $schema->properties);
    $this->assertArrayHasKey('age', $schema->properties);

    // Verify nested property types.
    $this->assertSame(DataType::STRING, $schema->properties['name']->type);
    $this->assertSame('Full name', $schema->properties['name']->description);
    $this->assertSame(DataType::INTEGER, $schema->properties['age']->type);

    // Verify required array passed through.
    $this->assertSame(['name', 'age'], $schema->required);
  }

  /**
   * Tests converting a schema with nested objects.
   *
   * Example: {"person": {"address": {"street": "...", "city": "..."}}}
   *
   * @covers ::convertJsonSchemaToGeminiSchema
   * @covers ::buildGeminiSchemaFromArray
   */
  public function testConvertNestedObject(): void {
    $schema = $this->invokeMethod('convertJsonSchemaToGeminiSchema', [
      [
        'schema' => [
          'type' => 'object',
          'properties' => [
            'address' => [
              'type' => 'object',
              'properties' => [
                'street' => ['type' => 'string'],
                'city' => ['type' => 'string'],
              ],
              'required' => ['street'],
            ],
          ],
        ],
      ],
    ]);

    $this->assertSame(DataType::OBJECT, $schema->type);
    $address = $schema->properties['address'];
    $this->assertSame(DataType::OBJECT, $address->type);
    $this->assertCount(2, $address->properties);
    $this->assertSame(DataType::STRING, $address->properties['street']->type);
    $this->assertSame(['street'], $address->required);
  }

  /**
   * Tests converting an array schema with typed items.
   *
   * Example: {"tags": ["php", "drupal"]}
   *
   * @covers ::convertJsonSchemaToGeminiSchema
   * @covers ::buildGeminiSchemaFromArray
   */
  public function testConvertArrayWithItems(): void {
    $schema = $this->invokeMethod('convertJsonSchemaToGeminiSchema', [
      [
        'schema' => [
          'type' => 'object',
          'properties' => [
            'tags' => [
              'type' => 'array',
              'items' => ['type' => 'string'],
            ],
          ],
        ],
      ],
    ]);

    $tags = $schema->properties['tags'];
    $this->assertSame(DataType::ARRAY, $tags->type);
    $this->assertInstanceOf(Schema::class, $tags->items);
    $this->assertSame(DataType::STRING, $tags->items->type);
  }

  /**
   * Tests converting an array of objects.
   *
   * Example: {"books": [{"title": "...", "author": "..."}]}
   *
   * @covers ::convertJsonSchemaToGeminiSchema
   * @covers ::buildGeminiSchemaFromArray
   */
  public function testConvertArrayOfObjects(): void {
    $schema = $this->invokeMethod('convertJsonSchemaToGeminiSchema', [
      [
        'schema' => [
          'type' => 'object',
          'properties' => [
            'books' => [
              'type' => 'array',
              'items' => [
                'type' => 'object',
                'properties' => [
                  'title' => ['type' => 'string'],
                  'author' => ['type' => 'string'],
                ],
                'required' => ['title'],
              ],
            ],
          ],
        ],
      ],
    ]);

    $books = $schema->properties['books'];
    $this->assertSame(DataType::ARRAY, $books->type);
    $this->assertSame(DataType::OBJECT, $books->items->type);
    $this->assertCount(2, $books->items->properties);
    $this->assertSame(DataType::STRING, $books->items->properties['title']->type);
    $this->assertSame(['title'], $books->items->required);
  }

  /**
   * Tests that schema without the 'schema' wrapper key still works.
   *
   * Some callers may pass the schema directly without the OpenAI wrapper.
   *
   * @covers ::convertJsonSchemaToGeminiSchema
   * @covers ::buildGeminiSchemaFromArray
   */
  public function testConvertWithoutSchemaWrapper(): void {
    $schema = $this->invokeMethod('convertJsonSchemaToGeminiSchema', [
      ['type' => 'string', 'description' => 'A color name'],
    ]);

    $this->assertSame(DataType::STRING, $schema->type);
    $this->assertSame('A color name', $schema->description);
  }

  /**
   * Tests that enum values pass through to the Gemini Schema.
   *
   * @covers ::convertJsonSchemaToGeminiSchema
   * @covers ::buildGeminiSchemaFromArray
   */
  public function testConvertWithEnum(): void {
    $schema = $this->invokeMethod('convertJsonSchemaToGeminiSchema', [
      [
        'schema' => [
          'type' => 'string',
          'enum' => ['red', 'green', 'blue'],
        ],
      ],
    ]);

    $this->assertSame(DataType::STRING, $schema->type);
    $this->assertSame(['red', 'green', 'blue'], $schema->enum);
  }

  /**
   * Tests all primitive data types are mapped correctly.
   *
   * @covers ::convertJsonSchemaToGeminiSchema
   * @covers ::buildGeminiSchemaFromArray
   */
  public function testConvertAllPrimitiveTypes(): void {
    $type_map = [
      'string' => DataType::STRING,
      'integer' => DataType::INTEGER,
      'number' => DataType::NUMBER,
      'boolean' => DataType::BOOLEAN,
    ];

    foreach ($type_map as $json_type => $expected_gemini_type) {
      $schema = $this->invokeMethod('convertJsonSchemaToGeminiSchema', [
        ['schema' => ['type' => $json_type]],
      ]);
      $this->assertSame(
        $expected_gemini_type,
        $schema->type,
        "JSON type '$json_type' should map to Gemini DataType::{$expected_gemini_type->name}"
      );
    }
  }

  /**
   * Tests the real-world "book" schema from AI module testing docs.
   *
   * This is the exact schema used in the AI module's provider testing guide.
   *
   * @covers ::convertJsonSchemaToGeminiSchema
   * @covers ::buildGeminiSchemaFromArray
   */
  public function testConvertBookSchemaFromDocs(): void {
    $schema = $this->invokeMethod('convertJsonSchemaToGeminiSchema', [
      [
        'schema' => [
          'properties' => [
            'name' => [
              'title' => 'Name',
              'type' => 'string',
            ],
            'authors' => [
              'items' => ['type' => 'string'],
              'title' => 'Authors',
              'type' => 'array',
            ],
          ],
          'required' => ['name', 'authors'],
          'title' => 'Book',
          'type' => 'object',
          'additionalProperties' => FALSE,
        ],
        'name' => 'book',
        'strict' => TRUE,
      ],
    ]);

    $this->assertSame(DataType::OBJECT, $schema->type);
    $this->assertCount(2, $schema->properties);

    // The name property.
    $this->assertSame(DataType::STRING, $schema->properties['name']->type);

    // The authors property — array of strings.
    $authors = $schema->properties['authors'];
    $this->assertSame(DataType::ARRAY, $authors->type);
    $this->assertSame(DataType::STRING, $authors->items->type);

    // Required fields.
    $this->assertSame(['name', 'authors'], $schema->required);
  }

  /**
   * Tests all supported DataFormat values map correctly from JSON schema.
   *
   * Gemini supports format hints on primitives: float, double, int32, int64,
   * enum, and date-time. These are passed through DataFormat::tryFrom().
   *
   * @covers ::convertJsonSchemaToGeminiSchema
   * @covers ::buildGeminiSchemaFromArray
   */
  public function testConvertAllFormats(): void {
    $format_map = [
      ['json_type' => 'number', 'format' => 'float', 'expected' => DataFormat::FLOAT],
      ['json_type' => 'number', 'format' => 'double', 'expected' => DataFormat::DOUBLE],
      ['json_type' => 'integer', 'format' => 'int32', 'expected' => DataFormat::INT32],
      ['json_type' => 'integer', 'format' => 'int64', 'expected' => DataFormat::INT64],
      ['json_type' => 'string', 'format' => 'enum', 'expected' => DataFormat::ENUM],
      ['json_type' => 'string', 'format' => 'date-time', 'expected' => DataFormat::DATETIME],
    ];

    foreach ($format_map as $case) {
      $schema = $this->invokeMethod('convertJsonSchemaToGeminiSchema', [
        [
          'schema' => [
            'type' => $case['json_type'],
            'format' => $case['format'],
          ],
        ],
      ]);
      $this->assertSame(
        $case['expected'],
        $schema->format,
        "Format '{$case['format']}' on type '{$case['json_type']}' should map to DataFormat::{$case['expected']->name}"
      );
    }
  }

  /**
   * Tests that an unrecognized format value results in NULL.
   *
   * DataFormat::tryFrom() returns NULL for unknown strings. We must not
   * crash on unexpected formats — just ignore them gracefully.
   *
   * @covers ::convertJsonSchemaToGeminiSchema
   * @covers ::buildGeminiSchemaFromArray
   */
  public function testConvertUnknownFormatReturnsNull(): void {
    $schema = $this->invokeMethod('convertJsonSchemaToGeminiSchema', [
      [
        'schema' => [
          'type' => 'string',
          'format' => 'uri',
        ],
      ],
    ]);

    $this->assertNull($schema->format);
  }

  /**
   * Tests that format is preserved on nested object properties.
   *
   * Format applies to leaf properties inside objects. Verify it survives
   * the recursive buildGeminiSchemaFromArray() call.
   *
   * @covers ::convertJsonSchemaToGeminiSchema
   * @covers ::buildGeminiSchemaFromArray
   */
  public function testFormatOnNestedProperty(): void {
    $schema = $this->invokeMethod('convertJsonSchemaToGeminiSchema', [
      [
        'schema' => [
          'type' => 'object',
          'properties' => [
            'price' => [
              'type' => 'number',
              'format' => 'float',
            ],
            'created' => [
              'type' => 'string',
              'format' => 'date-time',
            ],
            'count' => [
              'type' => 'integer',
              'format' => 'int32',
            ],
          ],
        ],
      ],
    ]);

    $this->assertSame(DataFormat::FLOAT, $schema->properties['price']->format);
    $this->assertSame(DataFormat::DATETIME, $schema->properties['created']->format);
    $this->assertSame(DataFormat::INT32, $schema->properties['count']->format);
  }

  /**
   * Tests that format is preserved on array item schemas.
   *
   * When items in an array have a format, it must carry through.
   *
   * @covers ::convertJsonSchemaToGeminiSchema
   * @covers ::buildGeminiSchemaFromArray
   */
  public function testFormatOnArrayItems(): void {
    $schema = $this->invokeMethod('convertJsonSchemaToGeminiSchema', [
      [
        'schema' => [
          'type' => 'object',
          'properties' => [
            'scores' => [
              'type' => 'array',
              'items' => [
                'type' => 'number',
                'format' => 'double',
              ],
            ],
          ],
        ],
      ],
    ]);

    $this->assertSame(DataFormat::DOUBLE, $schema->properties['scores']->items->format);
  }

  /**
   * Tests that omitting format yields NULL (no format set).
   *
   * @covers ::convertJsonSchemaToGeminiSchema
   * @covers ::buildGeminiSchemaFromArray
   */
  public function testNoFormatDefaultsToNull(): void {
    $schema = $this->invokeMethod('convertJsonSchemaToGeminiSchema', [
      ['schema' => ['type' => 'string']],
    ]);

    $this->assertNull($schema->format);
  }

}
