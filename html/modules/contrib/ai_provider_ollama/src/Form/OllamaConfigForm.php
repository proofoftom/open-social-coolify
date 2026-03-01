<?php

namespace Drupal\ai_provider_ollama\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Service\AiProviderFormHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Ollama API access.
 */
class OllamaConfigForm extends ConfigFormBase {

  /**
   * Constructs a new Azure Provider Config object.
   */
  final public function __construct(
    protected AiProviderPluginManager $aiProviderManager,
    protected AiProviderFormHelper $formHelper,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  final public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai.provider'),
      $container->get('ai.form_helper')
    );
  }

  /**
   * Config settings.
   */
  const CONFIG_NAME = 'ai_provider_ollama.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ollama_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      static::CONFIG_NAME,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::CONFIG_NAME);

    $form['host_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Host Name'),
      '#description' => $this->t('The host name for the API, including protocol, typically http://127.0.0.1 on a server. Use http://host.docker.internal for DDEV, see <a href="https://www.drupal.org/docs/extending-drupal/contributed-modules/contributed-module-documentation/ai/how-to-set-up-a-provider">AI documentation</a>.'),
      '#required' => TRUE,
      '#default_value' => $config->get('host_name'),
      '#attributes' => [
        'placeholder' => 'http://127.0.0.1 or http://host.docker.internal for DDEV, Docker, etc.',
      ],
    ];

    $form['port'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Port'),
      '#description' => $this->t('The port number for the API. Can be left empty if 80 or 443. The Ollama default port is usually 11434.'),
      '#default_value' => $config->get('port') ?? 11434,
    ];

    $provider = $this->aiProviderManager->createInstance('ollama');
    $form['models'] = $this->formHelper->getModelsTable($form, $form_state, $provider);

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\ai_provider_ollama\Plugin\AiProvider\OllamaProvider $provider */
    $provider = $this->aiProviderManager->createInstance('ollama');

    // Temporarily set the form values for validation.
    $provider->setConfiguration([
      'host_name' => $form_state->getValue('host_name'),
      'port' => $form_state->getValue('port'),
    ]);

    try {
      // Test connectivity by attempting to get configured models.
      $provider->getConfiguredModels();
    }
    catch (\Exception) {
      $form_state->setErrorByName('host_name', $this->t('Could not connect to the host. Please check the host name and port.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Retrieve the configuration.
    $this->config(static::CONFIG_NAME)
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('host_name', $form_state->getValue('host_name'))
      ->set('port', $form_state->getValue('port'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
