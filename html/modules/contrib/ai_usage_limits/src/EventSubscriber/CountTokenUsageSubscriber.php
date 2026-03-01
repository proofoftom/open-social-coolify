<?php

namespace Drupal\ai_usage_limits\EventSubscriber;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\State\StateInterface;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\PostStreamingResponseEvent;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Counts token usage.
 */
class CountTokenUsageSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the CountTokenUsageSubscriber object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(protected StateInterface $state) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PostGenerateResponseEvent::EVENT_NAME => 'countTokenUsage',
      PostStreamingResponseEvent::EVENT_NAME => 'countTokenUsage',
    ];
  }

  /**
   * Count the token usage.
   *
   * @param \Drupal\ai\Event\PostGenerateResponseEvent|\Drupal\ai\Event\PostStreamingResponseEvent $event
   *   The event to count.
   */
  public function countTokenUsage(PostGenerateResponseEvent|PostStreamingResponseEvent $event): void {
    // Fetch token usage from event.
    $output = $event->getOutput();
    if ($output instanceof ChatOutput) {
      $tokenUsage = $output->getTokenUsage();

      // Fetch stored usage.
      $current_usage = $this->state->get('ai_usage_limits', []);
      $provider_id = $event->getProviderId();
      $provider_usage = $current_usage[$provider_id] ?? [];

      // Initialize token usage counters.
      $provider_usage['input_token_usage'] ??= 0;
      $provider_usage['output_token_usage'] ??= 0;
      $provider_usage['total_token_usage'] ??= 0;
      $provider_usage['cached_token_usage'] ??= 0;
      $provider_usage['reasoning_token_usage'] ??= 0;

      // Increase usage values.
      $provider_usage['input_token_usage'] += $tokenUsage->input ?? 0;
      $provider_usage['output_token_usage'] += $tokenUsage->output ?? 0;
      $provider_usage['total_token_usage'] += $tokenUsage->total ?? 0;
      $provider_usage['cached_token_usage'] += $tokenUsage->cached ?? 0;
      $provider_usage['input_token_usage'] += $tokenUsage->reasoning ?? 0;

      $current_usage[$provider_id] = $provider_usage;

      // Save the retention start date if not already set.
      $current_date = new DrupalDateTime();
      $current_usage[$provider_id]['retention_start_date'] ??= $current_date->getTimestamp();

      $this->state->set('ai_usage_limits', $current_usage);
    }
  }

}
