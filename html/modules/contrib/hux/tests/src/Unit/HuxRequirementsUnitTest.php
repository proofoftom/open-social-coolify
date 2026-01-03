<?php

declare(strict_types=1);

namespace Drupal\Tests\hux\Unit;

use Drupal\hux\Hooks\HuxHooks;
use Drupal\Tests\UnitTestCase;

/**
 * Tests requirements hook.
 *
 * @group hux
 * @coversDefaultClass \Drupal\hux\HuxDiscovery
 */
final class HuxRequirementsUnitTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Change when these constants move out of core/includes/install.inc.
    if (!defined('REQUIREMENT_OK')) {
      define('REQUIREMENT_INFO', -1);
      define('REQUIREMENT_OK', 0);
      define('REQUIREMENT_WARNING', 1);
      define('REQUIREMENT_ERROR', 2);
    }
  }

  /**
   * @covers \Drupal\hux\Hooks\HuxHooks::requirements
   *
   * @dataProvider providerRequirements
   */
  public function testRequirements(bool $isoptimize, string $environment, int $assertSeverity, string $assertMessage): void {
    $huxHooks = new HuxHooks(
      $this->getStringTranslationStub(),
      $environment,
      ['optimize' => $isoptimize],
    );

    $requirements = $huxHooks->requirements('runtime');
    $this->assertEquals($assertSeverity, $requirements['hux.optimize']['severity']);
    $this->assertEquals('Hux', (string) $requirements['hux.optimize']['title']);
    $this->assertEquals($assertMessage, (string) $requirements['hux.optimize']['value']);
  }

  /**
   * Data provider.
   */
  public static function providerRequirements(): array {
    return [
      'optimized mode in production' => [
        TRUE,
        'prod',
        0,
        'Hux is running in <a href="https://www.drupal.org/docs/contributed-modules/hux/hux-optimized-mode">optimized mode</a>. This mode can be switched off in development environments.',
      ],
      'non optimized mode in production' => [
        FALSE,
        'prod',
        1,
        'It is recommended to run Hux in <a href="https://www.drupal.org/docs/contributed-modules/hux/hux-optimized-mode">optimized mode</a> when deployed to production environments. This warning can be ignored on development environments.',
      ],
      'optimized mode in development' => [
        TRUE,
        'dev',
        0,
        'Hux is running in <a href="https://www.drupal.org/docs/contributed-modules/hux/hux-optimized-mode">optimized mode</a>. This mode can be switched off in development environments.',
      ],
      'non optimized mode in development' => [
        FALSE,
        'dev',
        1,
        'It is recommended to run Hux in <a href="https://www.drupal.org/docs/contributed-modules/hux/hux-optimized-mode">optimized mode</a>. This warning can be ignored on development environments.',
      ],
    ];
  }

}
