<?php

namespace Drupal\waap_login\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'WaaP Login' block.
 *
 * @Block(
 *   id = "waap_login_block",
 *   admin_label = @Translation("WaaP Login"),
 *   category = @Translation("User")
 * )
 */
class WaapLoginBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new WaapLoginBlock instance.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    AccountInterface $current_user,
    ConfigFactoryInterface $config_factory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->currentUser = $current_user;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->configFactory->get('waap_login.settings');

    // Check if module is enabled.
    if (!$config->get('enabled')) {
      return [];
    }

    // Check if user has permission to use WaaP authentication.
    if (!$this->currentUser->hasPermission('use waap authentication')) {
      return [];
    }

    // Prepare drupalSettings for JavaScript.
    $drupalSettings = [
      'waap_login' => [
        'enabled' => $config->get('enabled'),
        'use_staging' => $config->get('use_staging'),
        'authentication_methods' => $config->get('authentication_methods'),
        'allowed_socials' => $config->get('allowed_socials'),
        'walletconnect_project_id' => $config->get('walletconnect_project_id'),
        'enable_dark_mode' => $config->get('enable_dark_mode'),
        'show_secured_badge' => $config->get('show_secured_badge'),
        'require_email_verification' => $config->get('require_email_verification'),
        'require_username' => $config->get('require_username'),
        'auto_create_users' => $config->get('auto_create_users'),
        'session_ttl' => $config->get('session_ttl'),
        'referral_code' => $config->get('referral_code'),
        'gas_tank_enabled' => $config->get('gas_tank_enabled'),
      ],
    ];

    // Different render based on authentication status.
    if ($this->currentUser->isAuthenticated()) {
      // User is logged in - show logout button and user info.
      $user = \Drupal::entityTypeManager()->getStorage('user')->load($this->currentUser->id());
      $walletAddress = '';
      if ($user && $user->hasField('field_ethereum_address')) {
        $walletAddress = $user->get('field_ethereum_address')->value ?? '';
      }

      return [
        '#theme' => 'waap_logout_button',
        '#username' => $this->currentUser->getAccountName(),
        '#wallet_address' => $walletAddress,
        '#user_email' => $this->currentUser->getEmail(),
        '#user_profile_url' => '/user/' . $this->currentUser->id(),
        '#attached' => [
          'library' => ['waap_login/sdk'],
          'drupalSettings' => $drupalSettings,
        ],
        '#cache' => [
          'contexts' => ['user'],
          'tags' => ['config:waap_login.settings'],
          'max-age' => Cache::PERMANENT,
        ],
      ];
    }

    // User is anonymous - show login button.
    return [
      '#theme' => 'waap_login_button',
      '#authentication_methods' => $config->get('authentication_methods'),
      '#walletconnect_project_id' => $config->get('walletconnect_project_id'),
      '#enable_dark_mode' => $config->get('enable_dark_mode'),
      '#show_secured_badge' => $config->get('show_secured_badge'),
      '#attached' => [
        'library' => ['waap_login/sdk'],
        'drupalSettings' => $drupalSettings,
      ],
      '#cache' => [
        'contexts' => ['user.roles:anonymous'],
        'tags' => ['config:waap_login.settings'],
        'max-age' => Cache::PERMANENT,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['user.roles:anonymous']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return Cache::mergeTags(parent::getCacheTags(), ['config:waap_login.settings']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return Cache::PERMANENT;
  }

}
