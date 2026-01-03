<?php

declare(strict_types=1);

namespace Drupal\Tests\hux\Unit;

use Drupal\hux\HuxCompilerPass;
use Drupal\hux_auto_test\Hooks\HuxAutoContainerInjection;
use Drupal\hux_auto_test\Hooks\HuxAutoSingle;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests compiler pass.
 *
 * @group hux
 * @coversDefaultClass \Drupal\hux\HuxCompilerPass
 */
final class HuxCompilerPassUnitTest extends UnitTestCase {

  /**
   * Tests automatic discovery of classes in Hooks directories.
   */
  public function testAutoClassDiscovery(): void {
    $parameterBag = $this->createMock(ParameterBagInterface::class);
    $parameterBag->expects($this->any())
      ->method('get')
      ->willReturnMap([
        ['hux', ['optimize' => FALSE]],
        [
          'container.namespaces',
          [
            'Drupal\hux_auto_test' => realpath(__DIR__ . '/../../modules/hux_auto_test/src'),
          ],
        ],
      ]);
    $containerBuilder = new ContainerBuilder($parameterBag);

    $huxModuleHandlerDefinition = \Mockery::mock(Definition::class);
    $huxModuleHandlerDefinition
      ->expects('isPublic')
      ->andReturn(TRUE);
    $huxModuleHandlerDefinition
      ->expects('setArgument')
      ->andReturnSelf();
    $huxModuleHandlerDefinition
      ->expects('hasTag')
      ->andReturn(TRUE);
    $containerBuilder->setDefinition('hux.module_handler', $huxModuleHandlerDefinition);

    $huxModuleHandlerDefinition
      ->expects('addMethodCall')
      ->once()
      ->withArgs(function ($methodName, $args) {
        // Order of values in $args[0] seems to vary by testing
        // environment so simply assert quantity.
        // This number matches the quantity of Hux implementations in
        // 'tests/modules/hux_auto_test/src/Hooks`.
        return $methodName === 'unoptimizedDiscovery' && count($args[0]) === 6;
      })
      ->andReturnSelf();

    (new HuxCompilerPass())->process($containerBuilder);

    $definition = $containerBuilder->getDefinition(HuxAutoContainerInjection::class);
    $this->assertEquals([HuxAutoContainerInjection::class, 'create'], $definition->getFactory());
    /** @var \Symfony\Component\DependencyInjection\Reference $arg1 */
    $arg1 = $definition->getArgument(0);
    $this->assertInstanceOf(Reference::class, $arg1);
    $this->assertEquals('service_container', (string) $arg1);

    $definition = $containerBuilder->getDefinition(HuxAutoSingle::class);
    $this->assertNull($definition->getFactory());
    $this->assertCount(0, $definition->getArguments());
  }

}
