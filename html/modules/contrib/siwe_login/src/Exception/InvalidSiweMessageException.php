<?php

namespace Drupal\siwe_login\Exception;

/**
 * Exception thrown when a SIWE message is invalid.
 */
class InvalidSiweMessageException extends \Exception {

  public function __construct($message = "", $code = 0, ?\Throwable $previous = NULL) {
    parent::__construct($message, $code, $previous);
    \Drupal::logger('siwe_login')->error('Invalid SIWE message: @message', [
      '@message' => $message,
    ]);
  }

}
