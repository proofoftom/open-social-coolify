<?php

namespace Drupal\waap_login\Exception;

/**
 * Exception for session-related errors.
 *
 * This exception is thrown when WaaP session validation fails,
 * such as expired sessions, invalid session data, or session storage failures.
 *
 * @ingroup waap_login
 */
class WaapSessionException extends WaapAuthenticationException {

  /**
   * Error code constants.
   */

  /**
   * Session has expired.
   */
  const ERROR_SESSION_EXPIRED = 1201;

  /**
   * Session data is invalid or corrupted.
   */
  const ERROR_INVALID_SESSION_DATA = 1202;

  /**
   * Session not found.
   */
  const ERROR_SESSION_NOT_FOUND = 1203;

  /**
   * Session storage failure.
   */
  const ERROR_STORAGE_FAILURE = 1204;

  /**
   * Session token validation failed.
   */
  const ERROR_TOKEN_VALIDATION_FAILED = 1205;

  /**
   * Session ID is malformed.
   */
  const ERROR_INVALID_SESSION_ID = 1206;

  /**
   * Session is already active for another user.
   */
  const ERROR_SESSION_ALREADY_ACTIVE = 1207;

  /**
   * Unknown or unspecified session error.
   */
  const ERROR_UNKNOWN = 1299;

  /**
   * Constructs a WaapSessionException.
   *
   * @param string $message
   *   The exception message.
   * @param int $code
   *   The exception code.
   * @param array $context
   *   Additional context information. Typically includes 'session_id' key.
   * @param \Throwable|null $previous
   *   The previous throwable used for exception chaining.
   */
  public function __construct($message = "", $code = 0, array $context = [], \Throwable $previous = NULL) {
    parent::__construct($message, $code, $context, $previous);
  }

  /**
   * Creates exception for expired session.
   *
   * @param string $session_id
   *   The expired session ID.
   * @param int $expired_at
   *   The timestamp when the session expired.
   *
   * @return static
   *   The exception instance.
   */
  public static function sessionExpired($session_id, $expired_at) {
    return new static(
      sprintf('WaaP session has expired: %s', $session_id),
      self::ERROR_SESSION_EXPIRED,
      [
        'session_id' => $session_id,
        'expired_at' => $expired_at,
      ]
    );
  }

  /**
   * Creates exception for invalid session data.
   *
   * @param string $session_id
   *   The session ID with invalid data.
   * @param string $reason
   *   The reason for invalidation.
   *
   * @return static
   *   The exception instance.
   */
  public static function invalidSessionData($session_id, $reason = '') {
    $message = sprintf('Invalid WaaP session data: %s', $session_id);
    if ($reason) {
      $message .= sprintf(' - %s', $reason);
    }
    return new static(
      $message,
      self::ERROR_INVALID_SESSION_DATA,
      [
        'session_id' => $session_id,
        'reason' => $reason,
      ]
    );
  }

  /**
   * Creates exception for session not found.
   *
   * @param string $session_id
   *   The session ID that was not found.
   *
   * @return static
   *   The exception instance.
   */
  public static function sessionNotFound($session_id) {
    return new static(
      sprintf('WaaP session not found: %s', $session_id),
      self::ERROR_SESSION_NOT_FOUND,
      ['session_id' => $session_id]
    );
  }

  /**
   * Creates exception for storage failure.
   *
   * @param string $operation
   *   The operation that failed (read/write/delete).
   * @param string $session_id
   *   The session ID involved.
   *
   * @return static
   *   The exception instance.
   */
  public static function storageFailure($operation, $session_id) {
    return new static(
      sprintf('WaaP session storage failure during %s for session: %s', $operation, $session_id),
      self::ERROR_STORAGE_FAILURE,
      [
        'session_id' => $session_id,
        'operation' => $operation,
      ]
    );
  }

  /**
   * Creates exception for token validation failure.
   *
   * @param string $session_id
   *   The session ID with invalid token.
   *
   * @return static
   *   The exception instance.
   */
  public static function tokenValidationFailed($session_id) {
    return new static(
      sprintf('WaaP session token validation failed: %s', $session_id),
      self::ERROR_TOKEN_VALIDATION_FAILED,
      ['session_id' => $session_id]
    );
  }

  /**
   * Creates exception for invalid session ID.
   *
   * @param string $session_id
   *   The malformed session ID.
   *
   * @return static
   *   The exception instance.
   */
  public static function invalidSessionId($session_id) {
    return new static(
      sprintf('Invalid WaaP session ID format: %s', $session_id),
      self::ERROR_INVALID_SESSION_ID,
      ['session_id' => $session_id]
    );
  }

  /**
   * Creates exception for session already active.
   *
   * @param string $session_id
   *   The session ID that is already active.
   *
   * @return static
   *   The exception instance.
   */
  public static function sessionAlreadyActive($session_id) {
    return new static(
      sprintf('WaaP session is already active: %s', $session_id),
      self::ERROR_SESSION_ALREADY_ACTIVE,
      ['session_id' => $session_id]
    );
  }

}
