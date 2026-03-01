<?php

namespace Drupal\ai_provider_ollama\Plugin\AiProvider;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\OpenAiBasedProviderClientBase;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\ai\OperationType\Moderation\ModerationInput;
use Drupal\ai\OperationType\Moderation\ModerationOutput;
use Drupal\ai\OperationType\Moderation\ModerationResponse;
use Drupal\ai\Traits\OperationType\ChatTrait;
use Drupal\ai_provider_ollama\Models\Moderation\LlamaGuard3;
use Drupal\ai_provider_ollama\Models\Moderation\ShieldGemma;
use Drupal\ai_provider_ollama\OllamaControlApi;
use GuzzleHttp\Client as GuzzleClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'ollama' provider.
 */
#[AiProvider(
  id: 'ollama',
  label: new TranslatableMarkup('Ollama'),
)]
class OllamaProvider extends OpenAiBasedProviderClientBase {

  use StringTranslationTrait;
  use ChatTrait;

  /**
   * The Ollama Control API for configuration calls.
   *
   * @var \Drupal\ai_provider_ollama\OllamaControlApi
   */
  protected OllamaControlApi $controlApi;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * Stores the state storage service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The transliteration helper.
   *
   * @var \Drupal\Component\Transliteration\TransliterationInterface
   */
  protected $transliteration;

  /**
   * Ollama Models.
   *
   * @var array
   */
  protected $models = [];

  /**
   * Dependency Injection for the Ollama Control API.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->controlApi = $container->get('ai_provider_ollama.control_api');
    $instance->controlApi->setConnectData($instance->getBaseHost());
    $instance->currentUser = $container->get('current_user');
    $instance->messenger = $container->get('messenger');
    $instance->state = $container->get('state');
    $instance->transliteration = $container->get('transliteration');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    // Graceful failure.
    try {
      $response = $this->controlApi->getModels();
    }
    catch (\Exception $e) {
      if ($this->currentUser->hasPermission('administer ai providers')) {
        $this->messenger->addError($this->t('Failed to get models from Ollama: @error', ['@error' => $e->getMessage()]));
      }
      $this->loggerFactory->get('ai_provider_ollama')->error('Failed to get models from Ollama: @error', ['@error' => $e->getMessage()]);
      return [];
    }
    $models = [];
    if (isset($response['models'])) {
      foreach ($response['models'] as $model) {
        $model_id = $this->getMachineName($model['model']);
        $root_model = explode(':', $model['model'])[0];
        if ($operation_type == 'moderation') {
          if (in_array($root_model, [
            'shieldgemma',
            'llama-guard3',
          ])) {
            $models[$model_id] = $model['name'];
          }
        }
        else {
          $models[$model_id] = $model['name'];
        }
      }
    }

    // Store models.
    $this->state->set('ai_provider_ollama.models', $models);
    $this->models = $models;

    return $models;
  }

  /**
   * Get model by id from model list.
   */
  protected function getModel($model_id) {
    if (empty($this->models)) {
      $this->models = $this->state->get('ai_provider_ollama.models') ?? $this->getConfiguredModels();
    }
    return $this->models[$model_id] ?? $model_id;
  }

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    // If its one of the bundles that Ollama supports its usable.
    if (!$this->getBaseHost()) {
      return FALSE;
    }
    if ($operation_type) {
      return in_array($operation_type, $this->getSupportedOperationTypes());
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return [
      'chat',
      'embeddings',
      'moderation',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
    // Doesn't do anything.
    $this->client = NULL;
  }

  /**
   * Get control client.
   *
   * This is the client for controlling the Ollama API.
   *
   * @return \Drupal\ai_provider_ollama\OllamaControlApi
   *   The control client.
   */
  public function getControlClient(): OllamaControlApi {
    return $this->controlApi;
  }

  /**
   * {@inheritdoc}
   */
  protected function loadClient(): void {
    if (empty($this->client)) {
      // Set custom endpoint from host config.
      $host = $this->getBaseHost();
      $this->setEndpoint($host . '/v1');

      // Override the HTTP client with longer timeout.
      $this->setHttpClient(new GuzzleClient(['timeout' => 600]));

      // Use parent's createClient method without authentication.
      $this->client = $this->createClient();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $model_id = $this->getModel($model_id);
    return parent::chat($input, $model_id, $tags);
  }

  /**
   * {@inheritdoc}
   */
  public function embeddings(string|EmbeddingsInput $input, string $model_id, array $tags = []): EmbeddingsOutput {
    $model_id = $this->getModel($model_id);
    return parent::embeddings($input, $model_id, $tags);
  }

  /**
   * {@inheritdoc}
   */
  public function moderation(string|ModerationInput $input, ?string $model_id = NULL, array $tags = []): ModerationOutput {
    $this->loadClient();
    // Normalize the input if needed.
    $chat_input[] = [
      'role' => 'user',
      'content' => $input instanceof ModerationInput ? $input->getPrompt() : $input,
    ];

    $model_id = $this->getModel($model_id);

    $payload = [
      'model' => $model_id,
      'messages' => $chat_input,
    ] + $this->configuration;

    $response = $this->client->chat()->create($payload)->toArray();
    if (!isset($response['choices'][0]['message']['content'])) {
      throw new AiRequestErrorException('No content in moderation response.');
    }
    $message = $response['choices'][0]['message']['content'];

    $moderation_response = new ModerationResponse(FALSE);
    $root_model_id = explode(':', $model_id)[0];
    switch ($root_model_id) {
      case 'llama-guard3':
        $moderation_response = LlamaGuard3::moderationRules($message);
        break;

      case 'shieldgemma':
        $moderation_response = ShieldGemma::moderationRules($message);
        break;

      default:
        throw new AiRequestErrorException('Model not supported for moderation.');

    }

    return new ModerationOutput($moderation_response, $message, $response);
  }

  /**
   * {@inheritdoc}
   */
  public function embeddingsVectorSize(string $model_id): int {
    $this->loadClient();
    $model_id = $this->getModel($model_id);
    $data = $this->controlApi->embeddingsVectorSize($model_id);
    if ($data) {
      return $data;
    }
    // Fallback to parent method.
    return parent::embeddingsVectorSize($model_id);
  }

  /**
   * Gets the base host.
   *
   * @return string
   *   The base host.
   */
  protected function getBaseHost(): string {
    $host = rtrim($this->getConfig()->get('host_name'), '/');
    if ($this->getConfig()->get('port')) {
      $host .= ':' . $this->getConfig()->get('port');
    }
    return $host;
  }

  /**
   * {@inheritdoc}
   */
  public function maxEmbeddingsInput($model_id = ''): int {
    $this->loadClient();
    $model_id = $this->getModel($model_id);
    return $this->controlApi->embeddingsContextSize($model_id);
  }

  /**
   * Generates a machine name from a string.
   *
   * This is basically the same as what is done in
   * \Drupal\Core\Block\BlockBase::getMachineNameSuggestion() and
   * \Drupal\system\MachineNameController::transliterate(), but it seems
   * that so far there is no common service for handling this.
   * Difference: We replacing '.' also.
   *
   * @param string $string
   *   String to have translated.
   *
   * @return string
   *   The machine name.
   *
   * @see \Drupal\Core\Block\BlockBase::getMachineNameSuggestion()
   * @see \Drupal\system\MachineNameController::transliterate()
   */
  protected function getMachineName($string): string {
    $transliterated = $this->transliteration->transliterate($string, LanguageInterface::LANGCODE_DEFAULT, '_');
    $transliterated = mb_strtolower($transliterated);

    $transliterated = preg_replace('@[^a-z0-9_]+@', '_', $transliterated);

    return $transliterated;
  }

}
