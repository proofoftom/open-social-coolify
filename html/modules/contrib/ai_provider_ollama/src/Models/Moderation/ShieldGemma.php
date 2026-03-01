<?php

namespace Drupal\ai_provider_ollama\Models\Moderation;

use Drupal\ai\OperationType\Moderation\ModerationResponse;

/**
 * The rules for Shield Gemma.
 */
class ShieldGemma {

  /**
   * Model data name.
   *
   * @var string
   */
  public static $modelName = 'shieldgemma';

  /**
   * Moderation rules.
   *
   * @param string $response
   *   The response from the Shield Gemma.
   *
   * @return \Drupal\ai\OperationType\Moderation\ModerationResponse
   *   The moderation response.
   */
  public static function moderationRules(string $response): ModerationResponse {
    if (strtolower($response) == 'yes') {
      return new ModerationResponse(TRUE, [], NULL);
    }
    return new ModerationResponse(FALSE, [], NULL);
  }

}
