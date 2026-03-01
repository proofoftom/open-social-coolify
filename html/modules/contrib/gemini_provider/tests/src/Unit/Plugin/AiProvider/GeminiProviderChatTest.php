<?php

declare(strict_types=1);

namespace Drupal\Tests\gemini_provider\Unit\Plugin\AiProvider;

use Drupal\gemini_provider\Plugin\AiProvider\GeminiProvider;
use Gemini\Data\Content;
use Gemini\Enums\Role;
use PHPUnit\Framework\TestCase;

/**
 * Tests GeminiProvider chat-related pure logic.
 *
 * @coversDefaultClass \Drupal\gemini_provider\Plugin\AiProvider\GeminiProvider
 * @group gemini_provider
 */
class GeminiProviderChatTest extends TestCase {

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
   * Tests that setChatSystemRole() maps to the Gemini 'model' role.
   *
   * Gemini only supports 'model' and 'user' roles. System instructions
   * must use the 'model' role — using 'user' would silently break prompts.
   *
   * @covers ::setChatSystemRole
   */
  public function testSetChatSystemRoleUsesModelRole(): void {
    $this->provider->setChatSystemRole('You are a helpful assistant.');

    $property = new \ReflectionProperty(GeminiProvider::class, 'systemMessage');
    $system_message = $property->getValue($this->provider);

    $this->assertInstanceOf(Content::class, $system_message);
    $this->assertSame(Role::MODEL, $system_message->role);
  }

  /**
   * Tests that setChatSystemRole() with empty string leaves systemMessage NULL.
   *
   * @covers ::setChatSystemRole
   */
  public function testSetChatSystemRoleEmpty(): void {
    $this->provider->setChatSystemRole('');

    $property = new \ReflectionProperty(GeminiProvider::class, 'systemMessage');
    $this->assertNull($property->getValue($this->provider));
  }

  /**
   * Tests that setChatSystemRole() with NULL leaves systemMessage NULL.
   *
   * @covers ::setChatSystemRole
   */
  public function testSetChatSystemRoleNull(): void {
    $this->provider->setChatSystemRole(NULL);

    $property = new \ReflectionProperty(GeminiProvider::class, 'systemMessage');
    $this->assertNull($property->getValue($this->provider));
  }

}
