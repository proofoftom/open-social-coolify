<?php

namespace Drupal\ai_usage_limits;

use Drupal\ai\AiProviderPluginManager;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Helper class for schema validation.
 */
class SchemaValidation {

  /**
   * The validation method referenced in the schema.yml file.
   *
   * @param mixed $value
   *   The value to validate. In this case, it's the entire 'providers' array.
   * @param \Symfony\Component\Validator\Context\ExecutionContextInterface $context
   *   The validation context.
   */
  public static function validateProviderKeys(array $value, ExecutionContextInterface $context): void {
    // Get the available AI providers.
    $availableProviders = [];
    $aiProviderPluginManager = \Drupal::service('ai.provider');
    if ($aiProviderPluginManager instanceof AiProviderPluginManager) {
      $availableProviders = array_keys($aiProviderPluginManager->getDefinitions());
    }

    // Get the keys submitted in the configuration.
    $submittedKeys = array_keys($value);

    // Find any submitted keys that are NOT in our list of valid keys.
    $invalidKeys = array_diff($submittedKeys, $availableProviders);

    if (!empty($invalidKeys)) {
      // If invalid keys are found, add a violation.
      $context->addViolation(
        sprintf('Invalid provider keys found: %s. Allowed keys are: %s.',
          implode(', ', $invalidKeys),
          implode(', ', $availableProviders)
        )
      );
    }
  }

}
