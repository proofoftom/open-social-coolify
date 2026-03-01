<?php

namespace Drupal\ai_vdb_provider_qdrant\Form;

use Drupal\ai\AiVdbProviderPluginManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Qdrant vector DB config form.
 */
class QdrantConfigForm extends ConfigFormBase {

  /**
   * Constructor of the Qdrant vector DB config form.
   *
   * @param \Drupal\ai\AiVdbProviderPluginManager $vdbProviderPluginManager
   *   The VDB Provider plugin manager.
   * @param \Drupal\key\KeyRepositoryInterface $keyRepository
   *   The key repository.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    protected AiVdbProviderPluginManager $vdbProviderPluginManager,
    protected KeyRepositoryInterface $keyRepository,
  ) {
    $this->vdbProviderPluginManager = $vdbProviderPluginManager;
    $this->keyRepository = $keyRepository;
    parent::__construct($configFactory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('ai.vdb_provider'),
      $container->get('key.repository'),
    );
  }

  /**
   * Config settings.
   */
  const CONFIG_NAME = 'ai_vdb_provider_qdrant.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_vdb_provider_qdrant_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [
      static::CONFIG_NAME,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(static::CONFIG_NAME);

    $form['host'] = [
      '#type' => 'textfield',
      '#required' => TRUE,
      '#title' => $this->t('Host'),
      '#description' => $this->t('The server host to connect to.'),
      '#default_value' => $config->get('host'),
    ];

    $form['port'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Port'),
      '#description' => $this->t('The server port to connect to. Qdrant\'s default port is 6333'),
      '#default_value' => $config->get('port') ?? '6333',
    ];

    $form['api_key'] = [
      '#type' => 'key_select',
      '#required' => FALSE,
      '#title' => $this->t('API Key'),
      '#description' => $this->t('The API key used to authenticate with the Qdrant server (optional).'),
      '#default_value' => $config->get('api_key'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $host = $form_state->getValue('host');
    // If (!filter_var($host, FILTER_VALIDATE_URL)) {
    //      $form_state->setErrorByName('host', $this->t('The host must be a valid URL.'));
    //    }.
    $port = $form_state->getValue('port');
    if (!empty($port) && !is_numeric($port)) {
      $form_state->setErrorByName('port', $this->t('The port must be a number.'));
    }

    // Test the connection.
    $qdrantConnector = $this->vdbProviderPluginManager->createInstance('qdrant');
    $apiKey = $form_state->getValue('api_key');
    if (!empty($apiKey)) {
      $apiKey = $this->keyRepository->getKey($apiKey)->getKeyValue();
    }
    $qdrantConnector->setCustomConfig([
      'host' => $host,
      'port' => $port,
      'api_key' => $apiKey,
    ]);

    if (!$qdrantConnector->ping()) {
      $form_state->setErrorByName('host', $this->t('Could not connect to the server.'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(static::CONFIG_NAME)
      ->set('host', rtrim($form_state->getValue('host'), '/'))
      ->set('port', $form_state->getValue('port'))
      ->set('api_key', $form_state->getValue('api_key'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
