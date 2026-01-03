<?php

namespace Drupal\group;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\Plugin\Discovery\AttributeDiscoveryWithAnnotations;
use Drupal\group\Plugin\Attribute\GroupRelationType;
use Drupal\group\Plugin\Group\Relation\GroupRelationTypeInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Alters existing services for the Group module.
 */
class GroupServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    // Automatically create missing handler services for group relations and
    // add important attributes to those already declared.
    $modules = $container->getParameter('container.modules');
    $discovery = new AttributeDiscoveryWithAnnotations(
      'Plugin/Group/Relation',
      $container->get('container.namespaces'),
      GroupRelationType::class,
      'Drupal\group\Annotation\GroupRelationType',
      []
    );

    $handler_info = [
      'access_control' => 'Drupal\group\Plugin\Group\RelationHandler\EmptyAccessControl',
      'entity_reference' => 'Drupal\group\Plugin\Group\RelationHandler\EmptyEntityReference',
      'operation_provider' => 'Drupal\group\Plugin\Group\RelationHandler\EmptyOperationProvider',
      'permission_provider' => 'Drupal\group\Plugin\Group\RelationHandler\EmptyPermissionProvider',
      'post_install' => 'Drupal\group\Plugin\Group\RelationHandler\EmptyPostInstall',
      'ui_text_provider' => 'Drupal\group\Plugin\Group\RelationHandler\EmptyUiTextProvider',
    ];

    // Keep track of which services are expected to be decorated.
    $decoratable_service_ids = array_map(fn($handler_id) => "group.relation_handler.$handler_id", array_keys($handler_info));

    // Keep track of the services that represent each relation type's handlers.
    $handlers = [];
    foreach ($discovery->getDefinitions() as $group_relation_type_id => $group_relation_type) {
      assert($group_relation_type instanceof GroupRelationTypeInterface);
      // Skip plugins that whose provider is not installed.
      if (!isset($modules[$group_relation_type->getProvider()])) {
        continue;
      }

      foreach ($handler_info as $handler_id => $handler_class) {
        $service_name = "group.relation_handler.$handler_id.$group_relation_type_id";
        $decoratable_service_ids[] = $service_name;

        // Either get the existing service or define it and pass it the default
        // one to decorate.
        $definition = $container->has($service_name)
          ? $container->getDefinition($service_name)
          : new Definition($handler_class, [new Reference("group.relation_handler.$handler_id")]);

        // All handlers must be public and cannot be shared.
        $definition->setPublic(TRUE);
        $definition->setShared(FALSE);
        $container->setDefinition($service_name, $definition);

        $handlers[$service_name] = new Reference($service_name);
      }
    }

    // Add the handlers to the relation type manager using a service locator.
    $manager = $container->getDefinition('group_relation_type.manager');
    $manager->addArgument(ServiceLocatorTagPass::register($container, $handlers));

    // Set the shared flag to FALSE for any service that decorates a base
    // handler or a relation type specific handler. This is a quality-of-life
    // feature so the handler system becomes easier to work with for people who
    // don't know what the shared flag does.
    foreach ($container->getDefinitions() as $definition) {
      if ($decorated = $definition->getDecoratedService()) {
        [$decorated_id] = $decorated;
        if (in_array($decorated_id, $decoratable_service_ids, TRUE)) {
          $definition->setShared(FALSE);
        }
      }
    }
  }

}
