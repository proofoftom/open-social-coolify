<?php

namespace Drupal\siwe_login\Exception;

/**
 * Exception thrown when wallet authentication fails.
 */
class WalletAuthenticationException extends \Exception {

  public function __construct($message = "", $code = 0, ?\Throwable $previous = NULL) {
    parent::__construct($message, $code, $previous);
    \Drupal::logger('siwe_login')->error('Wallet authentication failed: @message', [
      '@message' => $message,
    ]);
  }

}
