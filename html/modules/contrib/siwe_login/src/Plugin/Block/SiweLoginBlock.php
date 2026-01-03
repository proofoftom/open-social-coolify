<?php

namespace Drupal\siwe_login\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a 'SIWE Login' block.
 *
 * @Block(
 *   id = "siwe_login_block",
 *   admin_label = @Translation("SIWE Login Block"),
 *   category = @Translation("Authentication"),
 * )
 */
class SiweLoginBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new SiweLoginBlock.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ConfigFactoryInterface $config_factory,
    RequestStack $request_stack
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'button_text' => 'Sign in with Ethereum',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['button_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Button text'),
      '#default_value' => $this->configuration['button_text'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['button_text'] = $form_state->getValue('button_text');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->configFactory->get('siwe_login.settings');
    $use_web3onboard = $config->get('web3onboard_enabled');

    if ($use_web3onboard) {
      return $this->buildWeb3OnboardButton($config);
    }

    return $this->buildLegacyButton();
  }

  /**
   * Build the legacy button (direct MetaMask connection).
   *
   * @return array
   *   Render array for the legacy button.
   */
  protected function buildLegacyButton() {
    return [
      'siwe_login_button' => [
        '#type' => 'button',
        '#id' => 'siwe-login-button',
        '#value' => $this->configuration['button_text'],
        '#attributes' => [
          'class' => ['button', 'button--small', 'button--primary'],
        ],
        '#attached' => [
          'library' => [
            'siwe_login/siwe_login_js',
            'siwe_login/siwe_login_styles',
          ],
        ],
      ],
    ];
  }

  /**
   * Build the Web3Onboard button.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The SIWE configuration.
   *
   * @return array
   *   Render array for the Web3Onboard button.
   */
  protected function buildWeb3OnboardButton($config) {
    $site_config = $this->configFactory->get('system.site');
    $request = $this->requestStack->getCurrentRequest();

    $settings = [
      'siweLogin' => [
        'web3onboard' => [
          'enabled' => TRUE,
          'buttonText' => $this->configuration['button_text'],
          'appName' => $config->get('app_name') ?: $site_config->get('name'),
          'appIcon' => $config->get('app_icon'),
          'theme' => $config->get('onboard_theme') ?? 'system',
          'injectedWalletsEnabled' => $config->get('injected_wallets_enabled') ?? TRUE,
          'walletConnectEnabled' => $config->get('walletconnect_enabled') ?? FALSE,
          'walletConnectProjectId' => $config->get('walletconnect_project_id'),
        ],
      ],
    ];

    return [
      '#type' => 'container',
      '#attributes' => ['id' => 'siwe-login-container'],
      'button' => [
        '#type' => 'button',
        '#id' => 'siwe-login-button',
        '#value' => $this->configuration['button_text'],
        '#attributes' => [
          'class' => ['button', 'button--small', 'button--primary'],
        ],
      ],
      '#attached' => [
        'library' => [
          'siwe_login/siwe_login_web3onboard',
          'siwe_login/siwe_login_styles',
        ],
        'drupalSettings' => $settings,
      ],
    ];
  }

}
