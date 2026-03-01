<?php

namespace Drupal\ai_usage_limits\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ai\Event\ProviderDisabledEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reacts to AI provider events.
 */
class AiProviderEventSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the AiProviderEventSubscriber object.
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
      ProviderDisabledEvent::EVENT_NAME => 'providerDisabled',
    ];
  }

  /**
   * Reacts on AI providers being disabled.
   *
   * @param \Drupal\ai\Event\ProviderDisabledEvent $event
   *   The event object.
   */
  public function providerDisabled(ProviderDisabledEvent $event): void {
    $disabledProviderId = $event->getProviderId();

    // Update state to remove disabled AI provider.
    $current_usage = $this->state->get('ai_usage_limits', []);
    unset($current_usage[$disabledProviderId]);
    $this->state->set('ai_usage_limits', $current_usage);

    // Update config to remove disabled AI provider.
    $config = $this->configFactory->getEditable('ai_usage_limits.settings');
    $config->clear('providers.' . $disabledProviderId);
    $config->save();
  }

}
