<?php

declare(strict_types=1);

namespace Drupal\hux;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;

/**
 * Service provider for Hux.
 */
final class HuxServiceProvider implements ServiceProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    // Compiler pass should happen before ResolveInstanceofConditionalsPass,
    // which happens in TYPE_BEFORE_OPTIMIZATION (already, the earliest phase)
    // at priority 100. So only priority is effective for us:
    $container->addCompilerPass(
      new HuxCompilerPass(),
      type: PassConfig::TYPE_BEFORE_OPTIMIZATION,
      priority: 200,
    );
  }

}
