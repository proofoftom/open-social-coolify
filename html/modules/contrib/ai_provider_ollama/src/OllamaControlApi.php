<?php

namespace Drupal\ai_provider_ollama;

use Drupal\Component\Serialization\Json;
use GuzzleHttp\Client;

/**
 * Ollama Control API.
 */
class OllamaControlApi {

  /**
   * The http client.
   */
  protected Client $client;

  /**
   * The base host.
   */
  protected string $baseHost;

  /**
   * Constructs a new Ollama AI object.
   *
   * @param \GuzzleHttp\Client $client
   *   Http client.
   */
  public function __construct(Client $client) {
    $this->client = $client;
  }

  /**
   * Sets connect data.
   *
   * @param string $baseUrl
   *   The base url.
   */
  public function setConnectData($baseUrl) {
    $this->baseHost = $baseUrl;
  }

  /**
   * Get all models in Ollama.
   *
   * @return array
   *   The response.
   */
  public function getModels() {
    $result = Json::decode($this->makeRequest("api/tags", [], 'GET'));
    return $result;
  }

  /**
   * Get embeddings vector for a string.
   *
   * @param string $text
   *   The text.
   * @param string $model
   *   The model.
   *
   * @return array
   *   The response.
   */
  public function embeddings($text, $model) {
    $result = Json::decode($this->makeRequest("api/embeddings", [], 'POST', [
      'prompt' => $text,
      'model' => $model,
    ]));
    return $result;
  }

  /**
   * Embeddings vector size.
   *
   * @param string $model
   *   The model.
   *
   * @return int
   *   Embeddings vector size for the model.
   */
  public function embeddingsVectorSize(string $model): int {
    $data = Json::decode($this->makeRequest("api/show", [], 'POST', [
      'model' => $model,
    ]));
    foreach ($data['model_info'] as $key => $value) {
      if (str_ends_with($key, 'embedding_length') && is_numeric($value)) {
        return $data['model_info'][$key];
      }
    }

    return 0;
  }

  /**
   * Embeddings context size.
   *
   * @param string $model
   *   The model.
   *
   * @return int
   *   Input context max size.
   */
  public function embeddingsContextSize(string $model): int {
    return Json::decode($this->makeRequest("api/show", [], 'POST', [
      'model' => $model,
    ]))['model_info']['llama.context_length'];
  }

  /**
   * Chat with Ollama, none OpenAI style.
   *
   * @param array $payload
   *   The payload.
   *
   * @return array
   *   The response.
   */
  public function chat(array $payload) {
    if (!isset($payload['stream'])) {
      $payload['stream'] = FALSE;
    }
    $result = Json::decode($this->makeRequest("api/chat", [], 'POST', $payload)->getContents());
    return $result;
  }

  /**
   * Make Ollama call.
   *
   * @param string $path
   *   The path.
   * @param array $query_string
   *   The query string.
   * @param string $method
   *   The method.
   * @param string $body
   *   Data to attach if POST/PUT/PATCH.
   * @param array $options
   *   Extra headers.
   *
   * @return string|object
   *   The return response.
   */
  protected function makeRequest($path, array $query_string = [], $method = 'GET', $body = '', array $options = []) {
    // Don't wait to long if its model.
    if ($path == 'api/tags') {
      $options['connect_timeout'] = 5;
      $options['timeout'] = 5;
    }
    else {
      $options['connect_timeout'] = 120;
      $options['read_timeout'] = 120;
      $options['timeout'] = 120;
    }

    // JSON unless its multipart.
    if (empty($options['multipart'])) {
      $options['headers']['Content-Type'] = 'application/json';
    }
    if ($body) {
      $options['body'] = Json::encode($body);
    }

    $new_url = rtrim($this->baseHost, '/') . '/' . $path;
    $new_url .= count($query_string) ? '?' . http_build_query($query_string) : '';

    $res = $this->client->request($method, $new_url, $options);

    return $res->getBody();
  }

}
