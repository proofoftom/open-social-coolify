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

    // ENS Settings container.
    $form['ens_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('ENS Settings'),
      '#open' => TRUE,
      '#description' => $this->t('Configure ENS (Ethereum Name Service) name resolution and validation.'),
    ];

    $form['ens_settings']['enable_ens_validation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable ENS Validation'),
      '#default_value' => $config->get('enable_ens_validation'),
      '#description' => $this->t('Enable validation that ENS names resolve to signing addresses. Uses Ethereum mainnet RPC.'),
    ];

    $form['ens_settings']['enable_reverse_ens_lookup'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Reverse ENS Lookup'),
      '#default_value' => $config->get('enable_reverse_ens_lookup') ?? TRUE,
      '#description' => $this->t('Automatically look up ENS names for addresses that do not provide one in the SIWE message. Useful when users connect via non-mainnet chains (e.g., Gnosis).'),
      '#states' => [
        'visible' => [
          ':input[name="enable_ens_validation"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ens_settings']['ethereum_provider_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Primary Ethereum RPC URL (Optional)'),
      '#default_value' => $config->get('ethereum_provider_url'),
      '#description' => $this->t('Optional custom RPC URL (Alchemy, Infura). Leave empty to use free public endpoints.'),
      '#states' => [
        'visible' => [
          ':input[name="enable_ens_validation"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ens_settings']['ethereum_fallback_urls'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Additional Fallback RPC URLs'),
      '#default_value' => implode("\n", $config->get('ethereum_fallback_urls') ?? []),
      '#description' => $this->t('One URL per line. These are tried if the primary URL fails. Free public endpoints (LlamaRPC, PublicNode, Ankr, Cloudflare) are automatically used as final fallbacks.'),
      '#states' => [
        'visible' => [
          ':input[name="enable_ens_validation"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ens_settings']['ens_cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('ENS Cache TTL (seconds)'),
      '#default_value' => $config->get('ens_cache_ttl') ?? 3600,
      '#min' => 60,
      '#max' => 86400,
      '#description' => $this->t('How long to cache ENS lookups. ENS records rarely change, so 1 hour (3600) is recommended.'),
      '#states' => [
        'visible' => [
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
    // Note: ethereum_provider_url is now optional - free public endpoints
    // are used as fallbacks.

    // Validate fallback URLs format if provided.
    $fallback_urls_raw = $form_state->getValue('ethereum_fallback_urls');
    if (!empty($fallback_urls_raw)) {
      $urls = array_filter(array_map('trim', explode("\n", $fallback_urls_raw)));
      foreach ($urls as $url) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
          $form_state->setErrorByName('ethereum_fallback_urls',
            $this->t('Invalid URL format: @url', ['@url' => $url]));
          break;
        }
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

    // Parse fallback URLs from textarea.
    $fallback_urls_raw = $form_state->getValue('ethereum_fallback_urls');
    $fallback_urls = [];
    if (!empty($fallback_urls_raw)) {
      $fallback_urls = array_filter(array_map('trim', explode("\n", $fallback_urls_raw)));
    }

    $this->config('siwe_login.settings')
      ->set('nonce_ttl', $form_state->getValue('nonce_ttl'))
      ->set('message_ttl', $form_state->getValue('message_ttl'))
      ->set('require_email_verification', $form_state->getValue('require_email_verification'))
      ->set('require_ens_or_username', $form_state->getValue('require_ens_or_username'))
      ->set('session_timeout', $session_timeout_seconds)
      ->set('enable_ens_validation', $form_state->getValue('enable_ens_validation'))
      ->set('enable_reverse_ens_lookup', $form_state->getValue('enable_reverse_ens_lookup'))
      ->set('ethereum_provider_url', $form_state->getValue('ethereum_provider_url'))
      ->set('ethereum_fallback_urls', $fallback_urls)
      ->set('ens_cache_ttl', $form_state->getValue('ens_cache_ttl'))
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
