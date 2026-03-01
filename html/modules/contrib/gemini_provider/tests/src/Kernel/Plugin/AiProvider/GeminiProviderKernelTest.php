<?php

declare(strict_types=1);

namespace Drupal\Tests\gemini_provider\Kernel\Plugin\AiProvider;

use Drupal\ai\Plugin\ProviderProxy;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests GeminiProvider plugin discovery and Drupal integration.
 *
 * @coversDefaultClass \Drupal\gemini_provider\Plugin\AiProvider\GeminiProvider
 * @group gemini_provider
 */
class GeminiProviderKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai',
    'key',
    'gemini_provider',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['gemini_provider']);
  }

  /**
   * Tests that the Gemini plugin is discovered and can be instantiated.
   *
   * The AI module wraps all providers in a ProviderProxy. Verifying we get
   * a proxy back confirms the plugin was discovered, instantiated, and
   * wrapped without error.
   *
   * @covers ::__construct
   */
  public function testPluginDiscovery(): void {
    $provider = \Drupal::service('ai.provider')->createInstance('gemini');
    $this->assertInstanceOf(ProviderProxy::class, $provider);
  }

  /**
   * Tests that isUsable() returns FALSE when no API key is configured.
   *
   * The default installed config has an empty api_key. The provider should
   * report itself as not usable until a key is set.
   *
   * @covers ::isUsable
   */
  public function testIsUsableWithoutApiKey(): void {
    $provider = \Drupal::service('ai.provider')->createInstance('gemini');
    $this->assertFalse($provider->isUsable());
  }

  /**
   * Tests that isUsable() returns TRUE when an API key is configured.
   *
   * @covers ::isUsable
   */
  public function testIsUsableWithApiKey(): void {
    $this->config('gemini_provider.settings')->set('api_key', 'test-key')->save();
    $provider = \Drupal::service('ai.provider')->createInstance('gemini');
    $this->assertTrue($provider->isUsable());
  }

  /**
   * Tests that isUsable() checks supported operation types.
   *
   * @covers ::isUsable
   */
  public function testIsUsableWithOperationType(): void {
    $this->config('gemini_provider.settings')->set('api_key', 'test-key')->save();
    $provider = \Drupal::service('ai.provider')->createInstance('gemini');
    $this->assertTrue($provider->isUsable('chat'));
    $this->assertTrue($provider->isUsable('embeddings'));
    $this->assertTrue($provider->isUsable('text_to_image'));
    $this->assertTrue($provider->isUsable('speech_to_text'));
  }

  /**
   * Tests that setConfiguration() normalizes Gemini-specific config values.
   *
   * Verifies three behaviors:
   * - stopSequences string is split into an array
   * - responseSchema and responseMimeType are removed
   * - Other configuration values are preserved.
   *
   * @covers ::setConfiguration
   */
  public function testSetConfiguration(): void {
    $provider = \Drupal::service('ai.provider')->createInstance('gemini');
    $provider->setConfiguration([
      'temperature' => 0.7,
      'maxOutputTokens' => 2048,
      'stopSequences' => 'END,STOP,DONE',
      'responseSchema' => '{"type":"object"}',
      'responseMimeType' => 'application/json',
    ]);

    $config = $provider->getConfiguration();

    // stopSequences string should be split into an array.
    $this->assertSame(['END', 'STOP', 'DONE'], $config['stopSequences']);

    // responseSchema and responseMimeType should be stripped.
    $this->assertArrayNotHasKey('responseSchema', $config);
    $this->assertArrayNotHasKey('responseMimeType', $config);

    // Other values should be preserved.
    $this->assertSame(0.7, $config['temperature']);
    $this->assertSame(2048, $config['maxOutputTokens']);
  }

}
