<?php

namespace Drupal\waap_login\Exception;

/**
 * Exception for invalid Ethereum address format.
 *
 * This exception is thrown when an Ethereum address validation fails,
 * such as malformed addresses, incorrect length, or checksum failures.
 *
 * @ingroup waap_login
 */
class WaapInvalidAddressException extends WaapAuthenticationException {

  /**
   * Error code constants.
   */

  /**
   * Invalid address format (not matching 0x + 40 hex chars).
   */
  const ERROR_INVALID_FORMAT = 1101;

  /**
   * Address length is incorrect.
   */
  const ERROR_INVALID_LENGTH = 1102;

  /**
   * Checksum validation failed (EIP-55).
   */
  const ERROR_CHECKSUM_FAILED = 1103;

  /**
   * Address contains invalid characters.
   */
  const ERROR_INVALID_CHARACTERS = 1104;

  /**
   * Address is missing required 0x prefix.
   */
  const ERROR_MISSING_PREFIX = 1105;

  /**
   * Unknown or unspecified address validation error.
   */
  const ERROR_UNKNOWN = 1199;

  /**
   * Constructs a WaapInvalidAddressException.
   *
   * @param string $message
   *   The exception message.
   * @param int $code
   *   The exception code.
   * @param array $context
   *   Additional context information. Typically includes 'address' key.
   * @param \Throwable|null $previous
   *   The previous throwable used for exception chaining.
   */
  public function __construct($message = "", $code = 0, array $context = [], \Throwable $previous = NULL) {
    parent::__construct($message, $code, $context, $previous);
  }

  /**
   * Creates exception for invalid address format.
   *
   * @param string $address
   *   The invalid address.
   *
   * @return static
   *   The exception instance.
   */
  public static function invalidFormat($address) {
    return new static(
      sprintf('Invalid Ethereum address format: %s', $address),
      self::ERROR_INVALID_FORMAT,
      ['address' => $address]
    );
  }

  /**
   * Creates exception for invalid address length.
   *
   * @param string $address
   *   The invalid address.
   * @param int $length
   *   The actual length found.
   *
   * @return static
   *   The exception instance.
   */
  public static function invalidLength($address, $length) {
    return new static(
      sprintf('Invalid Ethereum address length: %s (expected 42 characters, got %d)', $address, $length),
      self::ERROR_INVALID_LENGTH,
      ['address' => $address, 'length' => $length]
    );
  }

  /**
   * Creates exception for checksum validation failure.
   *
   * @param string $address
   *   The address with invalid checksum.
   *
   * @return static
   *   The exception instance.
   */
  public static function checksumFailed($address) {
    return new static(
      sprintf('Ethereum address checksum validation failed: %s', $address),
      self::ERROR_CHECKSUM_FAILED,
      ['address' => $address]
    );
  }

  /**
   * Creates exception for invalid characters in address.
   *
   * @param string $address
   *   The address with invalid characters.
   *
   * @return static
   *   The exception instance.
   */
  public static function invalidCharacters($address) {
    return new static(
      sprintf('Ethereum address contains invalid characters: %s', $address),
      self::ERROR_INVALID_CHARACTERS,
      ['address' => $address]
    );
  }

  /**
   * Creates exception for missing 0x prefix.
   *
   * @param string $address
   *   The address without prefix.
   *
   * @return static
   *   The exception instance.
   */
  public static function missingPrefix($address) {
    return new static(
      sprintf('Ethereum address missing 0x prefix: %s', $address),
      self::ERROR_MISSING_PREFIX,
      ['address' => $address]
    );
  }

}
