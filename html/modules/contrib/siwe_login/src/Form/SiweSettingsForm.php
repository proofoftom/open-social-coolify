<?php

namespace Drupal\siwe_login\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for SIWE login settings.
 */
class SiweSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'siwe_login_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'siwe_login.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('siwe_login.settings');

    // Note: The expected_domain setting is managed automatically by SIWE
    // Server when present, or defaults to the current host when SIWE Server
    // is not used.
    // It is not exposed in the UI to simplify configuration.
    $form['nonce_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Nonce TTL'),
      '#default_value' => $config->get('nonce_ttl'),
      '#description' => $this->t('Time-to-live for nonces in seconds.'),
      '#min' => 1,
    ];

    $form['message_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Message TTL'),
      '#default_value' => $config->get('message_ttl'),
      '#description' => $this->t('Time-to-live for SIWE messages in seconds.'),
      '#min' => 1,
    ];

    $form['require_ens_or_username'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require ENS or Username'),
      '#default_value' => $config->get('require_ens_or_username'),
      '#description' => $this->t("Require users to set a username if they don't have an ENS name."),
    ];

    $form['require_email_verification'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require Email Verification'),
      '#default_value' => $config->get('require_email_verification'),
      '#description' => $this->t('Require email verification for new users.'),
    ];

    // Convert session timeout from seconds to hours for user-friendly display.
    $session_timeout_hours = $config->get('session_timeout') / 3600;

    $form['session_timeout_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Session Timeout (hours)'),
      '#default_value' => $session_timeout_hours,
      '#description' => $this->t('Session timeout in hours. Default is 24 hours.'),
      '#min' => 1,
      '#step' => 1,
    ];

    $form['enable_ens_validation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable ENS Validation'),
      '#default_value' => $config->get('enable_ens_validation'),
      '#description' => $this->t('Enable validation that ENS names resolve to signing addresses. Requires a valid Ethereum provider URL.'),
    ];

    $form['ethereum_provider_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ethereum Provider URL'),
      '#default_value' => $config->get('ethereum_provider_url'),
      '#description' => $this->t('URL for the Ethereum RPC provider (Alchemy, Infura, etc.).'),
      '#states' => [
        'visible' => [
          ':input[name="enable_ens_validation"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="enable_ens_validation"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Web3Onboard settings.
    $form['web3onboard'] = [
      '#type' => 'details',
      '#title' => $this->t('Web3Onboard Settings'),
      '#open' => TRUE,
      '#description' => $this->t('Configure Web3Onboard for multi-wallet support. When disabled, the module uses direct MetaMask connection.'),
    ];

    $form['web3onboard']['web3onboard_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Web3Onboard'),
      '#default_value' => $config->get('web3onboard_enabled'),
      '#description' => $this->t('Use Web3Onboard for multi-wallet support including WalletConnect.'),
    ];

    $form['web3onboard']['injected_wallets_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Injected Wallets'),
      '#default_value' => $config->get('injected_wallets_enabled') ?? TRUE,
      '#description' => $this->t('Support browser extension wallets like MetaMask, Coinbase Wallet, etc.'),
      '#states' => [
        'visible' => [
          ':input[name="web3onboard_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['web3onboard']['walletconnect_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable WalletConnect'),
      '#default_value' => $config->get('walletconnect_enabled'),
      '#description' => $this->t('Support mobile wallet connections via WalletConnect v2.'),
      '#states' => [
        'visible' => [
          ':input[name="web3onboard_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['web3onboard']['walletconnect_project_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('WalletConnect Project ID'),
      '#default_value' => $config->get('walletconnect_project_id'),
      '#description' => $this->t('Your WalletConnect Cloud Project ID. Get one at <a href="@url" target="_blank">cloud.walletconnect.com</a>', ['@url' => 'https://cloud.walletconnect.com']),
      '#states' => [
        'visible' => [
          ':input[name="walletconnect_enabled"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="walletconnect_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['web3onboard']['app_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Application Name'),
      '#default_value' => $config->get('app_name') ?: \Drupal::config('system.site')->get('name'),
      '#description' => $this->t('Name shown in wallet connection prompts. Defaults to site name.'),
      '#states' => [
        'visible' => [
          ':input[name="web3onboard_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['web3onboard']['onboard_theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Theme'),
      '#options' => [
        'system' => $this->t('System (auto-detect)'),
        'light' => $this->t('Light'),
        'dark' => $this->t('Dark'),
      ],
      '#default_value' => $config->get('onboard_theme') ?? 'system',
      '#description' => $this->t('Visual theme for the Web3Onboard wallet selection modal.'),
      '#states' => [
        'visible' => [
          ':input[name="web3onboard_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue('enable_ens_validation')) {
      if (empty(trim($form_state->getValue('ethereum_provider_url')))) {
        $form_state->setErrorByName('ethereum_provider_url', $this->t('Ethereum Provider URL is required when ENS validation is enabled.'));
      }
    }

    if ($form_state->getValue('walletconnect_enabled')) {
      $project_id = trim($form_state->getValue('walletconnect_project_id'));
      if (empty($project_id)) {
        $form_state->setErrorByName('walletconnect_project_id',
          $this->t('WalletConnect Project ID is required when WalletConnect is enabled.'));
      }
      elseif (strlen($project_id) !== 32) {
        $form_state->setErrorByName('walletconnect_project_id',
          $this->t('WalletConnect Project ID should be 32 characters long.'));
      }
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Save all settings except expected_domain, which is managed
    // automatically.
    // Convert session timeout from hours to seconds.
    $session_timeout_seconds = $form_state->getValue('session_timeout_hours') * 3600;

    $this->config('siwe_login.settings')
      ->set('nonce_ttl', $form_state->getValue('nonce_ttl'))
      ->set('message_ttl', $form_state->getValue('message_ttl'))
      ->set('require_email_verification', $form_state->getValue('require_email_verification'))
      ->set('require_ens_or_username', $form_state->getValue('require_ens_or_username'))
      ->set('session_timeout', $session_timeout_seconds)
      ->set('ethereum_provider_url', $form_state->getValue('ethereum_provider_url'))
      ->set('enable_ens_validation', $form_state->getValue('enable_ens_validation'))
      ->set('web3onboard_enabled', $form_state->getValue('web3onboard_enabled'))
      ->set('walletconnect_enabled', $form_state->getValue('walletconnect_enabled'))
      ->set('walletconnect_project_id', $form_state->getValue('walletconnect_project_id'))
      ->set('injected_wallets_enabled', $form_state->getValue('injected_wallets_enabled'))
      ->set('app_name', $form_state->getValue('app_name'))
      ->set('onboard_theme', $form_state->getValue('onboard_theme'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
