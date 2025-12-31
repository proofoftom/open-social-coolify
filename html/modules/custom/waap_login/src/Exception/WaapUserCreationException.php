<?php

namespace Drupal\waap_login\Exception;

/**
 * Exception for user account creation failures.
 *
 * This exception is thrown when user account creation fails,
 * such as username conflicts, email validation failures,
 * or other account creation errors.
 *
 * @ingroup waap_login
 */
class WaapUserCreationException extends WaapAuthenticationException {

  /**
   * Error code constants.
   */

  /**
   * Username already exists.
   */
  const ERROR_USERNAME_EXISTS = 1401;

  /**
   * Email already exists.
   */
  const ERROR_EMAIL_EXISTS = 1402;

  /**
   * Username is invalid.
   */
  const ERROR_INVALID_USERNAME = 1403;

  /**
   * Email validation failed.
   */
  const ERROR_INVALID_EMAIL = 1404;

  /**
   * User creation failed due to database error.
   */
  const ERROR_DATABASE_FAILED = 1405;

  /**
   * User entity save failed.
   */
  const ERROR_SAVE_FAILED = 1406;

  /**
   * Email address is required but not provided.
   */
  const ERROR_EMAIL_REQUIRED = 1407;

  /**
   * Username is required but not provided.
   */
  const ERROR_USERNAME_REQUIRED = 1408;

  /**
   * Email domain is blocked.
   */
  const ERROR_EMAIL_DOMAIN_BLOCKED = 1409;

  /**
   * Username is reserved.
   */
  const ERROR_USERNAME_RESERVED = 1410;

  /**
   * User data validation failed.
   */
  const ERROR_VALIDATION_FAILED = 1411;

  /**
   * Unknown or unspecified user creation error.
   */
  const ERROR_UNKNOWN = 1499;

  /**
   * Constructs a WaapUserCreationException.
   *
   * @param string $message
   *   The exception message.
   * @param int $code
   *   The exception code.
   * @param array $context
   *   Additional context information. Typically includes 'username' or 'email' keys.
   * @param \Throwable|null $previous
   *   The previous throwable used for exception chaining.
   */
  public function __construct($message = "", $code = 0, array $context = [], \Throwable $previous = NULL) {
    parent::__construct($message, $code, $context, $previous);
  }

  /**
   * Creates exception for existing username.
   *
   * @param string $username
   *   The username that already exists.
   *
   * @return static
   *   The exception instance.
   */
  public static function usernameExists($username) {
    return new static(
      sprintf('Username already exists: %s', $username),
      self::ERROR_USERNAME_EXISTS,
      ['username' => $username]
    );
  }

  /**
   * Creates exception for existing email.
   *
   * @param string $email
   *   The email that already exists.
   *
   * @return static
   *   The exception instance.
   */
  public static function emailExists($email) {
    return new static(
      sprintf('Email address already exists: %s', $email),
      self::ERROR_EMAIL_EXISTS,
      ['email' => $email]
    );
  }

  /**
   * Creates exception for invalid username.
   *
   * @param string $username
   *   The invalid username.
   * @param string $reason
   *   The reason for invalidation.
   *
   * @return static
   *   The exception instance.
   */
  public static function invalidUsername($username, $reason = '') {
    $message = sprintf('Invalid username: %s', $username);
    if ($reason) {
      $message .= sprintf(' - %s', $reason);
    }
    return new static(
      $message,
      self::ERROR_INVALID_USERNAME,
      [
        'username' => $username,
        'reason' => $reason,
      ]
    );
  }

  /**
   * Creates exception for invalid email.
   *
   * @param string $email
   *   The invalid email.
   *
   * @return static
   *   The exception instance.
   */
  public static function invalidEmail($email) {
    return new static(
      sprintf('Invalid email address: %s', $email),
      self::ERROR_INVALID_EMAIL,
      ['email' => $email]
    );
  }

  /**
   * Creates exception for database failure during user creation.
   *
   * @param string $reason
   *   The reason for database failure.
   *
   * @return static
   *   The exception instance.
   */
  public static function databaseFailed($reason) {
    return new static(
      sprintf('User creation failed due to database error: %s', $reason),
      self::ERROR_DATABASE_FAILED,
      ['reason' => $reason]
    );
  }

  /**
   * Creates exception for save failure.
   *
   * @param string $reason
   *   The reason for save failure.
   *
   * @return static
   *   The exception instance.
   */
  public static function saveFailed($reason) {
    return new static(
      sprintf('User entity save failed: %s', $reason),
      self::ERROR_SAVE_FAILED,
      ['reason' => $reason]
    );
  }

  /**
   * Creates exception for missing required email.
   *
   * @return static
   *   The exception instance.
   */
  public static function emailRequired() {
    return new static(
      'Email address is required for user creation',
      self::ERROR_EMAIL_REQUIRED,
      []
    );
  }

  /**
   * Creates exception for missing required username.
   *
   * @return static
   *   The exception instance.
   */
  public static function usernameRequired() {
    return new static(
      'Username is required for user creation',
      self::ERROR_USERNAME_REQUIRED,
      []
    );
  }

  /**
   * Creates exception for blocked email domain.
   *
   * @param string $email
   *   The email with blocked domain.
   * @param string $domain
   *   The blocked domain.
   *
   * @return static
   *   The exception instance.
   */
  public static function emailDomainBlocked($email, $domain) {
    return new static(
      sprintf('Email domain is blocked: %s', $domain),
      self::ERROR_EMAIL_DOMAIN_BLOCKED,
      [
        'email' => $email,
        'domain' => $domain,
      ]
    );
  }

  /**
   * Creates exception for reserved username.
   *
   * @param string $username
   *   The reserved username.
   *
   * @return static
   *   The exception instance.
   */
  public static function usernameReserved($username) {
    return new static(
      sprintf('Username is reserved and cannot be used: %s', $username),
      self::ERROR_USERNAME_RESERVED,
      ['username' => $username]
    );
  }

  /**
   * Creates exception for general validation failure.
   *
   * @param string $reason
   *   The validation failure reason.
   *
   * @return static
   *   The exception instance.
   */
  public static function validationFailed($reason) {
    return new static(
      sprintf('User data validation failed: %s', $reason),
      self::ERROR_VALIDATION_FAILED,
      ['reason' => $reason]
    );
  }

}
