<?php

namespace Drupal\role_delegation\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Plugin\Action\AddRoleUser;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Trait to extend role user actions with role delegation access checks.
 */
trait RoleDelegationManagerRoleUserTrait {

  /**
   * The role delegation access checker.
   *
   * @var \Drupal\role_delegation\Access\RoleDelegationAccessCheck
   */
  protected $roleDelegationAccessCheck;

  /**
   * The delegatable roles service.
   *
   * @var \Drupal\role_delegation\DelegatableRolesInterface
   */
  protected $delegatableRoles;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->roleDelegationAccessCheck = $container->get('access_check.role_delegation');
    $instance->delegatableRoles = $container->get('delegatable_roles');
    $instance->currentUser = $container->get('current_user');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $access = parent::access($object, $account, TRUE);
    if ($access->isAllowed()) {
      return $access;
    }

    $permissions = [
      sprintf('assign %s role', $this->configuration['rid']),
      'assign all roles',
    ];
    $access = AccessResult::allowedIfHasPermissions($account, $permissions, 'OR');

    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['rid']['#options'] = $this->delegatableRoles->getAssignableRoles($this->currentUser);

    return $form;
  }

}
