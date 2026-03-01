<?php

namespace Drupal\ai_usage_limits\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\RedundantEditableConfigNamesTrait;
use Drupal\Core\State\StateInterface;
use Drupal\ai\AiProviderPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form that allows setting AI usage limits.
 */
class SettingsForm extends ConfigFormBase {

  use RedundantEditableConfigNamesTrait;

  /**
   * Constructs the settings form.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager.
   * @param \Drupal\ai\AiProviderPluginManager $providerManager
   *   The AI provider plugin manager.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected AiProviderPluginManager $providerManager,
    protected StateInterface $state,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('ai.provider'),
      $container->get('state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_usage_limits_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('ai_usage_limits.settings');
    $form['#tree'] = TRUE;

    $retention_days = $config->get('retention_days') ?? 30;
    $form['retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Retention days'),
      '#description' => $this->t('After how many days should we reset the usage counters. This happens when running cron.'),
      '#config_target' => 'ai_usage_limits.settings:retention_days',
      '#min' => 1,
    ];

    // Get current usage limits.
    $current_usage = $this->state->get('ai_usage_limits', []);

    $form['providers'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Limits for AI Providers'),
    ];

    foreach ($this->providerManager->getDefinitions() as $provider_id => $provider_info) {
      $form['provider_' . $provider_id] = [
        '#type' => 'details',
        '#title' => $provider_info['label'] ?? $provider_id,
        '#group' => 'providers',
      ];

      $form['provider_' . $provider_id]['enable_limits'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable usage limits'),
        '#config_target' => 'ai_usage_limits.settings:providers.' . $provider_id . '.enable_limits',
      ];

      $limit_fields = [
        'input_token_usage' => $this->t('Input token usage'),
        'output_token_usage' => $this->t('Output token usage'),
        'total_token_usage' => $this->t('Total token usage'),
        'cached_token_usage' => $this->t('Cached token usage'),
        'reasoning_token_usage' => $this->t('Reasoning token usage'),
      ];

      foreach ($limit_fields as $key => $label) {
        $form['provider_' . $provider_id][$key] = [
          '#type' => 'number',
          '#title' => $label,
          '#config_target' => new ConfigTarget(
            'ai_usage_limits.settings',
            'providers.' . $provider_id . '.' . $key,
            // Converts config value to a form value.
            fn($value) => is_null($value) ? 0 : $value,
            // Converts form value to a config value.
            fn($value) => $value,
          ),
          '#states' => [
            'visible' => [
              ':input[name="provider_' . $provider_id . '[enable_limits]"]' => ['checked' => TRUE],
            ],
          ],
        ];

        if (!empty($current_usage[$provider_id][$key])) {
          $form['provider_' . $provider_id][$key]['#description'] = $this->t('Used @amount tokens in the last @days days', [
            '@amount' => $current_usage[$provider_id][$key],
            '@days' => $retention_days,
          ]);
        }
      }
    }

    return parent::buildForm($form, $form_state);
  }

}
