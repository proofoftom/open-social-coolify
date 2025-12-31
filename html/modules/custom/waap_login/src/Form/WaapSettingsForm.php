<?php

namespace Drupal\waap_login\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Configure WaaP Login settings.
 */
class WaapSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['waap_login.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'waap_login_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('waap_login.settings');

    $form['#attached']['library'][] = 'waap_login/waap_login';

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable WaaP Login'),
      '#default_value' => $config->get('enabled'),
      '#description' => $this->t('Enable WaaP authentication on the site.'),
    ];

    $form['sdk_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('WaaP SDK Settings'),
      '#open' => TRUE,
    ];

    $form['sdk_settings']['use_staging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use staging environment'),
      '#default_value' => $config->get('use_staging'),
      '#description' => $this->t('Use the WaaP staging environment instead of production.'),
    ];

    $form['sdk_settings']['authentication_methods'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled authentication methods'),
      '#default_value' => $config->get('authentication_methods'),
      '#options' => [
        'email' => $this->t('Email'),
        'phone' => $this->t('Phone'),
        'social' => $this->t('Social (Google, Twitter, Discord, etc.)'),
        'wallet' => $this->t('Wallet (MetaMask, WalletConnect)'),
      ],
      '#description' => $this->t('Select which authentication methods to enable in the WaaP modal.'),
    ];

    $form['sdk_settings']['allowed_socials'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Allowed social providers'),
      '#default_value' => $config->get('allowed_socials'),
      '#options' => [
        'google' => $this->t('Google'),
        'twitter' => $this->t('Twitter'),
        'discord' => $this->t('Discord'),
        'coinbase' => $this->t('Coinbase'),
        'linkedin' => $this->t('LinkedIn'),
        'apple' => $this->t('Apple'),
        'github' => $this->t('GitHub'),
      ],
      '#description' => $this->t('Select which social providers to allow in the WaaP modal.'),
      '#states' => [
        'visible' => [
          ':input[name="authentication_methods[social]"]' => ['checked'],
        ],
      ],
    ];

    $form['sdk_settings']['walletconnect_project_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('WalletConnect Project ID'),
      '#default_value' => $config->get('walletconnect_project_id'),
      '#description' => $this->t('Enter your WalletConnect Project ID from <a href=":url" target="_blank">WalletConnect Cloud</a>.', [':url' => 'https://cloud.walletconnect.com/']),
    ];

    $form['sdk_settings']['enable_dark_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable dark mode'),
      '#default_value' => $config->get('enable_dark_mode'),
      '#description' => $this->t('Use dark theme for the WaaP modal.'),
    ];

    $form['sdk_settings']['show_secured_badge'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show secured by Human.tech badge'),
      '#default_value' => $config->get('show_secured_badge'),
      '#description' => $this->t('Display the "Secured by Human.tech" badge in the WaaP modal.'),
    ];

    $form['sdk_settings']['referral_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Referral code'),
      '#default_value' => $config->get('referral_code'),
      '#description' => $this->t('Optional referral code for WaaP integration.'),
    ];

    $form['sdk_settings']['gas_tank_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Gas Tank for sponsored transactions'),
      '#default_value' => $config->get('gas_tank_enabled'),
      '#description' => $this->t('Enable Gas Tank to allow sponsored transactions for users.'),
    ];

    $form['user_management'] = [
      '#type' => 'details',
      '#title' => $this->t('User Management'),
      '#open' => TRUE,
    ];

    $form['user_management']['require_email_verification'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require email verification for new users'),
      '#default_value' => $config->get('require_email_verification'),
      '#description' => $this->t('Require new users to verify their email address before completing login.'),
    ];

    $form['user_management']['require_username'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require custom username'),
      '#default_value' => $config->get('require_username'),
      '#description' => $this->t('Require new users to create a custom username instead of auto-generating one.'),
    ];

    $form['user_management']['auto_create_users'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically create user accounts'),
      '#default_value' => $config->get('auto_create_users'),
      '#description' => $this->t('Automatically create Drupal user accounts for new WaaP users. If disabled, users must already exist.'),
    ];

    $form['session_management'] = [
      '#type' => 'details',
      '#title' => $this->t('Session Management'),
      '#open' => TRUE,
    ];

    $form['session_management']['session_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Session TTL (seconds)'),
      '#default_value' => $config->get('session_ttl'),
      '#description' => $this->t('Time in seconds before WaaP session expires. Default: 86400 (24 hours).'),
      '#min' => 60,
      '#step' => 60,
    ];

    $form['integration'] = [
      '#type' => 'details',
      '#title' => $this->t('Integration'),
      '#open' => TRUE,
    ];

    $form['integration']['siwe_integration'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable SIWE Login integration'),
      '#default_value' => $config->get('siwe_integration', FALSE),
      '#description' => $this->t('Allow WaaP and SIWE Login to share the same Ethereum address field. Users can authenticate with either method.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('waap_login.settings')
      ->set('enabled', $form_state->getValue('enabled'))
      ->set('use_staging', $form_state->getValue('use_staging'))
      ->set('authentication_methods', array_filter($form_state->getValue('authentication_methods')))
      ->set('allowed_socials', array_filter($form_state->getValue('allowed_socials')))
      ->set('walletconnect_project_id', $form_state->getValue('walletconnect_project_id'))
      ->set('enable_dark_mode', $form_state->getValue('enable_dark_mode'))
      ->set('show_secured_badge', $form_state->getValue('show_secured_badge'))
      ->set('require_email_verification', $form_state->getValue('require_email_verification'))
      ->set('require_username', $form_state->getValue('require_username'))
      ->set('auto_create_users', $form_state->getValue('auto_create_users'))
      ->set('session_ttl', (int) $form_state->getValue('session_ttl'))
      ->set('referral_code', $form_state->getValue('referral_code'))
      ->set('gas_tank_enabled', $form_state->getValue('gas_tank_enabled'))
      ->set('siwe_integration', $form_state->getValue('siwe_integration'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
