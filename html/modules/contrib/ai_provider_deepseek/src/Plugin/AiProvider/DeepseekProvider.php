<?php

namespace Drupal\ai_provider_deepseek\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use GuzzleHttp\Client;
use \DeepseekPhp\DeepseekClient;
use \DeepseekPhp\Enums\Models;
use Symfony\Component\Yaml\Yaml;

/**
 * Plugin implementation of the DeepSeek LLM.
 */
#[AiProvider(
  id: 'deepseek',
  label: new TranslatableMarkup('DeepSeek')
)]
class DeepSeekProvider extends AiProviderClientBase implements ChatInterface {

  /**
   * DeepSeek client
   *
   * @var DeepseekClient
   */
  protected $client;

  /**
   * API Key.
   *
   * @var string
   */
  protected string $apiKey = '';

  /**
   * Run moderation call, before a normal call.
   *
   * @var bool
   */
  protected bool $moderation = TRUE;

  /**
   * {@inheritdoc}
   * @param string|null $operation_type
   * @param array $capabilities
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    if (!$this->getConfig()->get('api_key')) {
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
    return ['chat', 'chat_with_tools'];
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get('ai_provider_deepseek.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getApiDefinition(): array {
    $definition = Yaml::parseFile(
      $this->moduleHandler->getModule('ai_provider_deepseek')
        ->getPath() . '/definitions/api_defaults.yml'
    );
    return $definition;
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
    $this->apiKey = $authentication;
    $this->client = NULL;
  }

  /**
   * Gets the raw client.
   *
   * @param string $api_key
   *   If the API key should be hot swapped.
   *
   * @return \DeepseekPhp\DeepseekClient
   *   The Deepseek client.
   */
  public function getClient(string $api_key = '') {
    if ($api_key) {
      $this->setAuthentication($api_key);
    }

    $this->loadClient();
    return $this->client;
  }

  /**
   * Loads the DeepSeek Client with authentication if not initialized.
   */
  protected function loadClient(): void {
    if (!$this->client) {
      if (!$this->apiKey) {
        $this->setAuthentication($this->loadApiKey());
      }

      $this->client = DeepseekClient::build($this->apiKey);
    }
  }

  /**
   * {@inheritdoc}
   * @param string|null $operation_type
   * @param array $capabilities
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    return [
      'deepseek-chat' => 'DeepSeek Chat',
      'deepseek-coder' => 'DeepSeek Coder'
    ];
  }

  /**
   * Load API key from key module.
   *
   * @return string
   *   The API key.
   */
  protected function loadApiKey(): string {
    return $this->keyRepository->getKey($this->getConfig()->get('api_key'))
      ->getKeyValue();
  }

  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    // Ensure API key is loaded.
    if (!$this->apiKey) {
      $this->setAuthentication($this->loadApiKey());
    }

    // Build the messages array in OpenAI-compatible format.
    $chat_input = [];

    if ($input instanceof ChatInput) {
      // Add system prompt if present.
      if ($input->getSystemPrompt()) {
        $chat_input[] = [
          'role' => 'system',
          'content' => $input->getSystemPrompt(),
        ];
      }

      // Convert ChatMessage objects to API format.
      foreach ($input->getMessages() as $message) {
        $role = $message->getRole();

        // Skip messages with empty or invalid roles - these can come from
        // corrupted tempstore data or incomplete serialization cycles.
        if (empty($role) || !in_array($role, ['system', 'user', 'assistant', 'tool'])) {
          \Drupal::logger('deepseek_debug')->warning('Skipping message with invalid role: "@role"', ['@role' => $role]);
          continue;
        }

        $content = $message->getText();
        $new_message = [
          'role' => $role,
          'content' => $content,
        ];

        // If it's a tool response, include tool_call_id.
        if ($message->getToolsId()) {
          $new_message['tool_call_id'] = $message->getToolsId();
        }

        // If the message contains tool calls from a previous assistant
        // response, include them so the API can match tool responses.
        if ($message->getTools()) {
          $new_message['tool_calls'] = $message->getRenderedTools();
          // DeepSeek API (OpenAI-compatible) requires content to be null
          // (not empty string) for assistant messages with tool_calls.
          if ($role === 'assistant' && $content === '') {
            $new_message['content'] = NULL;
          }
        }

        $chat_input[] = $new_message;
      }
    }
    elseif (is_string($input)) {
      $chat_input[] = [
        'role' => 'user',
        'content' => $input,
      ];
    }
    elseif (is_array($input)) {
      $chat_input = $input;
    }

    // Build the request payload.
    $payload = [
      'model' => $model_id,
      'messages' => $chat_input,
    ];

    // Add tool definitions if present (OpenAI-compatible format).
    $has_tools = FALSE;
    if ($input instanceof ChatInput && method_exists($input, 'getChatTools') && $input->getChatTools()) {
      $payload['tools'] = $input->getChatTools()->renderToolsArray();
      $has_tools = TRUE;
    }

    // Make the API request directly via Guzzle (the DeepSeek PHP client
    // library does not support tools, but the DeepSeek API is
    // OpenAI-compatible and fully supports function calling).
    $guzzle = new Client([
      'base_uri' => 'https://api.deepseek.com',
      'timeout' => 120,
      'headers' => [
        'Authorization' => 'Bearer ' . $this->apiKey,
        'Content-Type' => 'application/json',
      ],
    ]);

    // Debug log the exact payload being sent to DeepSeek.
    \Drupal::logger('deepseek_debug')->notice('Request payload: @body', [
      '@body' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    ]);

    try {
      $response = $guzzle->post('/chat/completions', [
        'json' => $payload,
      ]);
      $data = Json::decode($response->getBody()->getContents());
    }
    catch (\Exception $e) {
      \Drupal::logger('deepseek_debug')->error('DeepSeek API error. Payload was: @body', [
        '@body' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
      ]);
      throw new AiResponseErrorException($e->getMessage());
    }

    $role = $data['choices'][0]['message']['role'] ?? 'assistant';
    $content = $data['choices'][0]['message']['content'] ?? '';
    $message = new ChatMessage($role, $content);

    // Parse tool calls from the response if present.
    if ($has_tools && !empty($data['choices'][0]['message']['tool_calls'])) {
      $tools = [];
      foreach ($data['choices'][0]['message']['tool_calls'] as $tool) {
        $arguments = Json::decode($tool['function']['arguments']);
        $tools[] = new ToolsFunctionOutput(
          $input->getChatTools()->getFunctionByName($tool['function']['name']),
          $tool['id'],
          $arguments
        );
      }
      if (!empty($tools)) {
        $message->setTools($tools);
      }
    }

    return new ChatOutput($message, $data, []);
  }

}
