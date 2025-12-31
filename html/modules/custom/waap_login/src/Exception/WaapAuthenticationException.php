<?php

namespace Drupal\waap_login\Exception;

/**
 * Base exception class for WaaP authentication errors.
 *
 * This exception serves as the foundation for all authentication-related exceptions
 * in the WaaP Login module. It provides contextual information for debugging
 * and error handling through the context array.
 *
 * @ingroup waap_login
 */
class WaapAuthenticationException extends \Exception {

  /**
   * Error code constants.
   */

  /**
   * Invalid credentials provided.
   */
  const ERROR_INVALID_CREDENTIALS = 1001;

  /**
   * General authentication failure.
   */
  const ERROR_AUTHENTICATION_FAILED = 1002;

  /**
   * WaaP SDK error.
   */
  const ERROR_SDK_ERROR = 1003;

  /**
   * Network or connectivity error.
   */
  const ERROR_NETWORK_ERROR = 1004;

  /**
   * Unknown or unspecified error.
   */
  const ERROR_UNKNOWN = 1099;

  /**
   * Additional context information.
   *
   * @var array
   */
  protected $context = [];

  /**
   * Constructs a WaapAuthenticationException.
   *
   * @param string $message
   *   The exception message.
   * @param int $code
   *   The exception code.
   * @param array $context
   *   Additional context information for debugging.
   * @param \Throwable|null $previous
   *   The previous throwable used for exception chaining.
   */
  public function __construct($message = "", $code = 0, array $context = [], \Throwable $previous = NULL) {
    parent::__construct($message, $code, $previous);
    $this->context = $context;
  }

  /**
   * Gets the exception context.
   *
   * @return array
   *   The context array containing additional debugging information.
   */
  public function getContext() {
    return $this->context;
  }

  /**
   * Gets a specific context value.
   *
   * @param string $key
   *   The context key to retrieve.
   * @param mixed $default
   *   The default value to return if the key doesn't exist.
   *
   * @return mixed
   *   The context value or default if not found.
   */
  public function getContextValue($key, $default = NULL) {
    return $this->context[$key] ?? $default;
  }

}
