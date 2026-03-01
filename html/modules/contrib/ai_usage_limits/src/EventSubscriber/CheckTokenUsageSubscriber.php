<?php

namespace Drupal\ai_usage_limits\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai_usage_limits\Exception\AiTokenUsageException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Checks token usage.
 */
class CheckTokenUsageSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the CheckTokenUsageSubscriber object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(protected StateInterface $state, protected ConfigFactoryInterface $configFactory) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreGenerateResponseEvent::EVENT_NAME => 'checkTokenUsage',
    ];
  }

  /**
   * Checks token usage against the configured limits.
   *
   * @param \Drupal\ai\Event\PreGenerateResponseEvent $event
   *   The event object.
   *
   * @throws \Drupal\ai_usage_limits\Exception\AiTokenUsageException
   */
  public function checkTokenUsage(PreGenerateResponseEvent $event): void {
    $current_usage = $this->state->get('ai_usage_limits', []);
    $provider_id = $event->getProviderId();
    $provider_usage = $current_usage[$provider_id] ?? [];

    $usage_limits = $this->configFactory
      ->get('ai_usage_limits.settings')
      ->get('providers.' . $provider_id);

    foreach ($this->getUsages() as $usage) {
      if (!empty($provider_usage[$usage]) && $usage_limits[$usage] < $provider_usage[$usage]) {
        throw new AiTokenUsageException('Token limit reached for ' . $provider_id);
      }
    }
  }

  /**
   * Gets all the supported token types.
   *
   * @return string[]
   *   A list of supported token types.
   */
  private function getUsages(): array {
    return [
      'input_token_usage',
      'output_token_usage',
      'total_token_usage',
      'cached_token_usage',
      'reasoning_token_usage',
    ];
  }

}
