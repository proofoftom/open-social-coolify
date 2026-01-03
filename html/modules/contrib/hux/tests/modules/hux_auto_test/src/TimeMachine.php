<?php

declare(strict_types=1);

namespace Drupal\hux_auto_test;

use Drupal\Component\Datetime\TimeInterface;

/**
 * Service used to simulate time.
 */
class TimeMachine implements TimeInterface {

  protected \DateTimeImmutable $time;

  /**
   * Constructs a new TimeMachine.
   */
  public function __construct(string $time) {
    $this->time = new \DateTimeImmutable($time);
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestTime() {
    return $this->time->getTimestamp();
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestMicroTime() {
    return (float) $this->time->getTimestamp();
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentTime() {
    return $this->time->getTimestamp();
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentMicroTime() {
    return (float) $this->time->getTimestamp();
  }

}
