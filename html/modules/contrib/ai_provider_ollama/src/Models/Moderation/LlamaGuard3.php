<?php

namespace Drupal\ai_provider_ollama\Models\Moderation;

use Drupal\ai\OperationType\Moderation\ModerationResponse;

/**
 * The rules for llama guard.
 */
class LlamaGuard3 {

  /**
   * Model data name.
   *
   * @var string
   */
  public static $modelName = 'llama-guard3';

  /**
   * Moderation rules.
   *
   * @param string $response
   *   The response from the llama guard.
   *
   * @return \Drupal\ai\OperationType\Moderation\ModerationResponse
   *   The moderation response.
   */
  public static function moderationRules(string $response): ModerationResponse {
    $reasoning = [];
    if (substr($response, 0, 6) == 'unsafe') {
      switch (substr($response, 7)) {
        case 'S1':
          $reasoning[] = 'Violent Crimes';
          break;

        case 'S2':
          $reasoning[] = 'Non-Violent Crimes';
          break;

        case 'S3':
          $reasoning[] = 'Sex-Related Crimes';
          break;

        case 'S4':
          $reasoning[] = 'Child Sexual Exploitation';
          break;

        case 'S5':
          $reasoning[] = 'Defamation';
          break;

        case 'S6':
          $reasoning[] = 'Specialized Advice';
          break;

        case 'S7':
          $reasoning[] = 'Privacy';
          break;

        case 'S8':
          $reasoning[] = 'Intellectual Property';
          break;

        case 'S9':
          $reasoning[] = 'Indiscriminate Weapons';
          break;

        case 'S10':
          $reasoning[] = 'Hate';
          break;

        case 'S11':
          $reasoning[] = 'Suicide & Self-Harm';
          break;

        case 'S12':
          $reasoning[] = 'Sexual Content';
          break;

        case 'S13':
          $reasoning[] = 'Elections';
          break;
      }
    }

    // If the response is safe, return false.
    if (!count($reasoning)) {
      return new ModerationResponse(FALSE);
    }
    else {
      $message = t('Moderation triggered because of "@reasons" - see https://ollama.com/xe/llamaguard3 for more information.', [
        '@reasons' => implode(', ', $reasoning),
      ]);
      return new ModerationResponse(TRUE, $reasoning, $message);
    }

  }

}
