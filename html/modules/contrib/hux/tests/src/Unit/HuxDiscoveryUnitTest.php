<?php

declare(strict_types=1);

namespace Drupal\Tests\hux\Unit;

use Drupal\hux\HuxDiscovery;
use Drupal\hux_test\HuxTestHooks;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

/**
 * Tests compiler pass.
 *
 * @group hux
 * @coversDefaultClass \Drupal\hux\HuxDiscovery
 */
final class HuxDiscoveryUnitTest extends UnitTestCase {

  /**
   * Tests discovery.
   */
  public function testHuxDiscovery(): void {
    $parameterBag = new ParameterBag([
      'container.namespaces' => [
        'Drupal\hux_test' => realpath(__DIR__ . '/../../modules/hux_test/src'),
      ],
    ]);
    $containerBuilder = new ContainerBuilder($parameterBag);

    $definition = new Definition(HuxTestHooks::class);
    $definition
      ->addTag('hooks')
      ->setPublic(TRUE);
    $containerBuilder->setDefinition('foo.bar.service', $definition);

    $discovery = new HuxDiscovery(['foo.bar.service' => ['foo', HuxTestHooks::class, 'dummy service id']]);
    $discovery->discovery($containerBuilder);

    $this->assertCount(1, iterator_to_array($discovery->getHooks('test_hook')));
    $this->assertCount(1, iterator_to_array($discovery->getHooks('test_hook_returns')));
    $this->assertCount(0, iterator_to_array($discovery->getHooks('test_hook_doesnt_exist')));

    /** @var \Drupal\hux\HuxDiscovery $discovery */
    $discovery = \unserialize(\serialize($discovery));
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Hook implementations were cleared after serialization. Re-construct the discovery class.');
    $discovery->discovery($containerBuilder);
  }

}
