<?php

namespace Drupal\Tests\ai\Unit\OperationType\Chat;

use Drupal\ai\Dto\StructuredOutputSchema;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsPropertyInput;
use PHPUnit\Framework\TestCase;

/**
 * Tests that the normalization of the ChatInput and ChatOutput works.
 *
 * @group ai
 * @covers \Drupal\ai\OperationType\Chat\ChatInput
 */
class ChatArrayTest extends TestCase {

  /**
   * Test a very simple input from array.
   */
  public function testSimpleInputFromArray(): void {
    $input = new ChatInput([new ChatMessage('user', 'What is the weather today?')]);
    $array = $input->toArray();
    $this->assertIsArray($array);
    $this->assertArrayHasKey('messages', $array);
    $this->assertCount(1, $array['messages']);
    $this->assertEquals('user', $array['messages'][0]['role']);
    $this->assertEquals('What is the weather today?', $array['messages'][0]['text']);
    $this->assertArrayHasKey('debug_data', $array);
    $this->assertIsArray($array['debug_data']);
    $this->assertArrayHasKey('chat_tools', $array);
    $this->assertNull($array['chat_tools']);
    $this->assertArrayHasKey('chat_structured_json_schema', $array);
    $this->assertIsArray($array['chat_structured_json_schema']);
    $this->assertArrayHasKey('chat_strict_schema', $array);
    $this->assertFalse($array['chat_strict_schema']);
  }

  /**
   * Test to create a very simple input from array.
   */
  public function testSimpleInputFromArrayCreation(): void {
    $input = ChatInput::fromArray([
      'messages' => [
        [
          'role' => 'user',
          'text' => 'What is the weather today?',
          'images' => [],
          'tools' => NULL,
          'tool_id' => '',
        ],
      ],
      'debug_data' => [],
      'chat_tools' => NULL,
      'chat_strict_schema' => FALSE,
    ]);
    $this->assertInstanceOf(ChatInput::class, $input);
    $this->assertCount(1, $input->getMessages());
    $this->assertEquals('user', $input->getMessages()[0]->getRole());
    $this->assertEquals('What is the weather today?', $input->getMessages()[0]->getText());
    $this->assertEmpty($input->getMessages()[0]->getFiles());
    $this->assertNull($input->getChatTools());
    $this->assertEmpty($input->getChatStructuredJsonSchema());
    // @phpstan-ignore-next-line method.deprecated
    $this->assertFalse($input->getChatStrictSchema());
  }

  /**
   * Test to create a whole conversation from array.
   */
  public function testWholeConversationFromArray(): void {
    $input = ChatInput::fromArray([
      'messages' => [
        [
          'role' => 'user',
          'text' => 'What is the weather today?',
          'images' => [],
          'tools' => NULL,
          'tool_id' => '',
        ],
        [
          'role' => 'assistant',
          'text' => 'It is sunny and warm.',
          'images' => [],
          'tools' => NULL,
          'tool_id' => '',
        ],
      ],
      'debug_data' => [],
      'chat_tools' => NULL,
      'chat_strict_schema' => FALSE,
    ]);
    $this->assertCount(2, $input->getMessages());
    $this->assertEquals('user', $input->getMessages()[0]->getRole());
    $this->assertEquals('What is the weather today?', $input->getMessages()[0]->getText());
    $this->assertEquals('assistant', $input->getMessages()[1]->getRole());
    $this->assertEquals('It is sunny and warm.', $input->getMessages()[1]->getText());
    $this->assertEmpty($input->getMessages()[0]->getFiles());
    $this->assertEmpty($input->getMessages()[1]->getFiles());
    $this->assertNull($input->getChatTools());
    $this->assertEmpty($input->getChatStructuredJsonSchema());
    // @phpstan-ignore-next-line method.deprecated
    $this->assertFalse($input->getChatStrictSchema());
  }

  /**
   * Test adding a tool to the chat input.
   */
  public function testAddToolToChatInput(): void {
    $input = new ChatInput([new ChatMessage('user', 'What is the weather today?')]);
    $tool_spec = [
      'name' => 'Weather Tool',
      'description' => 'Provides weather information.',
      'parameters' => [
        'type' => 'object',
        'properties' => [
          'location' => [
            'name' => 'location',
            'type' => 'string',
            'description' => 'The location to get the weather for.',
          ],
        ],
        'required' => ['location'],
      ],
    ];
    $property = new ToolsPropertyInput('location');
    $property->setDescription('The location to get the weather for.');
    $property->setType('string');
    $property->setRequired(TRUE);
    $tool = new ToolsFunctionInput('weather_tools');
    $tool->setName('Weather Tool');
    $tool->setDescription('Provides weather information.');
    $tool->setProperty($property);
    $tool_input = new ToolsInput([$tool]);
    $input->setChatTools($tool_input);
    $array = $input->toArray();
    $this->assertNotNull($input->getChatTools());
    $this->assertEquals($tool_spec, $array['chat_tools'][0]['function']);
  }

  /**
   * Test adding a message with tool from an array.
   */
  public function testAddMessageWithToolFromArray(): void {
    $array = [
      'messages' => [
        [
          'role' => 'user',
          'text' => 'What is the weather today?',
          'images' => [],
          'tools' => NULL,
          'tool_id' => '',
        ],
      ],
      'debug_data' => [],
      'chat_tools' => [
        [
          'type' => 'function',
          'function' => [
            'name' => 'Weather Tool',
            'description' => 'Provides weather information.',
            'parameters' => [
              'type' => 'object',
              'properties' => [
                'location' => [
                  'name' => 'location',
                  'type' => 'string',
                  'description' => 'The location to get the weather for.',
                ],
              ],
              'required' => ['location'],
            ],
          ],
        ],
      ],
      'chat_strict_schema' => FALSE,
    ];
    $input = ChatInput::fromArray($array);
    $this->assertCount(1, $input->getMessages());
    $this->assertEquals('user', $input->getMessages()[0]->getRole());
    $this->assertEquals('What is the weather today?', $input->getMessages()[0]->getText());
    $this->assertEmpty($input->getMessages()[0]->getFiles());
    $this->assertNotNull($input->getChatTools());
    $this->assertEquals($array['chat_tools'], $input->getChatTools()->renderToolsArray());
  }

  /**
   * Test setting a StructuredOutputSchema object on ChatInput.
   */
  public function testStructuredOutputSchema(): void {
    $input = new ChatInput([new ChatMessage('user', 'What is the weather today?')]);

    // Create a StructuredOutputSchema object.
    $schema = new StructuredOutputSchema(
      name: 'test_schema',
      description: 'Test schema description',
      strict: TRUE,
      json_schema: [
        'properties' => [
          'temperature' => ['type' => 'number'],
          'location' => ['type' => 'string'],
        ],
      ],
    );

    // Set it on the input.
    $input->setChatStructuredJsonSchema($schema);

    // Verify it was converted to array correctly (stored with 'schema' key).
    $schema_array = $input->getChatStructuredJsonSchema();
    $this->assertIsArray($schema_array);
    $this->assertEquals('test_schema', $schema_array['name']);
    $this->assertEquals('Test schema description', $schema_array['description']);
    $this->assertTrue($schema_array['strict']);
    $this->assertArrayHasKey('schema', $schema_array);
    $this->assertIsArray($schema_array['schema']);
    $this->assertArrayHasKey('properties', $schema_array['schema']);

    // Test toArray() directly (uses 'schema' key for provider compatibility).
    $schema_to_array = $schema->toArray();
    $this->assertEquals('test_schema', $schema_to_array['name']);
    $this->assertEquals('Test schema description', $schema_to_array['description']);
    $this->assertTrue($schema_to_array['strict']);
    $this->assertArrayHasKey('schema', $schema_to_array);
    $this->assertIsArray($schema_to_array['schema']);
    $this->assertArrayHasKey('properties', $schema_to_array['schema']);
    // Verify validator is not in the array.
    $this->assertArrayNotHasKey('validator', $schema_to_array);
  }

  /**
   * Test creating StructuredOutputSchema from array with validation.
   */
  public function testStructuredOutputSchemaFromArray(): void {
    // Test valid schema with 'schema' key (default, documented).
    $schema = StructuredOutputSchema::fromArray([
      'name' => 'valid_schema',
      'description' => 'Valid schema',
      'strict' => FALSE,
      'schema' => [
        'properties' => [
          'field1' => ['type' => 'string'],
        ],
      ],
    ]);

    $this->assertInstanceOf(StructuredOutputSchema::class, $schema);
    $this->assertEquals('valid_schema', $schema->getName());
    $this->assertEquals('Valid schema', $schema->getDescription());
    $this->assertFalse($schema->isStrict());
    $this->assertIsArray($schema->getJsonSchema());

    // Test that 'json_schema' key is accepted (backwards compatibility).
    $schema_legacy = StructuredOutputSchema::fromArray([
      'name' => 'legacy_schema',
      'json_schema' => [
        'properties' => [
          'field2' => ['type' => 'number'],
        ],
      ],
    ]);
    $this->assertEquals('legacy_schema', $schema_legacy->getName());
    $this->assertArrayHasKey('field2', $schema_legacy->getJsonSchema()['properties']);

    // Test invalid schema name (should throw exception).
    $this->expectException(\InvalidArgumentException::class);
    // Contains uppercase and spaces.
    StructuredOutputSchema::fromArray([
      'name' => 'Invalid Schema Name',
      'schema' => [
        'properties' => [],
      ],
    ]);
  }

  /**
   * Test StructuredOutputSchema validation in setters.
   */
  public function testStructuredOutputSchemaValidation(): void {
    $schema = new StructuredOutputSchema();

    // Test valid name.
    $schema->setName('valid_name_123');
    $this->assertEquals('valid_name_123', $schema->getName());

    // Test invalid name (should throw exception).
    $this->expectException(\InvalidArgumentException::class);
    $schema->setName('Invalid Name');
  }

  /**
   * Test that ChatInput accepts 'json_schema' key and normalizes to 'schema'.
   */
  public function testChatInputAcceptsJsonSchemaKey(): void {
    $input = new ChatInput([new ChatMessage('user', 'Test')]);
    $input->setChatStructuredJsonSchema([
      'name' => 'book',
      'strict' => TRUE,
      'json_schema' => [
        'properties' => [
          'name' => ['type' => 'string'],
          'authors' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['name', 'authors'],
        'type' => 'object',
      ],
    ]);
    $stored = $input->getChatStructuredJsonSchema();
    $this->assertArrayHasKey('schema', $stored);
    $this->assertArrayNotHasKey('json_schema', $stored);
    $this->assertEquals('book', $stored['name']);
    $this->assertTrue($stored['strict']);
    $this->assertArrayHasKey('properties', $stored['schema']);
    $this->assertArrayHasKey('name', $stored['schema']['properties']);
  }

}
