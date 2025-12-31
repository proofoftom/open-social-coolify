<?php

namespace Drupal\waap_login\Exception;

/**
 * Exception for configuration errors.
 *
 * This exception is thrown when WaaP configuration is invalid,
 * such as missing required settings, invalid configuration values,
 * or configuration schema violations.
 *
 * @ingroup waap_login
 */
class WaapConfigurationException extends \RuntimeException {

  /**
   * Error code constants.
   */

  /**
   * Required configuration key is missing.
   */
  const ERROR_MISSING_CONFIG_KEY = 1301;

  /**
   * Configuration value is invalid.
   */
  const ERROR_INVALID_CONFIG_VALUE = 1302;

  /**
   * Configuration schema validation failed.
   */
  const ERROR_SCHEMA_VALIDATION_FAILED = 1303;

  /**
   * Required environment variable is not set.
   */
  const ERROR_MISSING_ENV_VAR = 1304;

  /**
   * Configuration is not writable.
   */
  const ERROR_CONFIG_NOT_WRITABLE = 1305;

  /**
   * Configuration has invalid type.
   */
  const ERROR_INVALID_CONFIG_TYPE = 1306;

  /**
   * Configuration value is out of allowed range.
   */
  const ERROR_VALUE_OUT_OF_RANGE = 1307;

  /**
   * Unknown or unspecified configuration error.
   */
  const ERROR_UNKNOWN = 1399;

  /**
   * Additional context information.
   *
   * @var array
   */
  protected $context = [];

  /**
   * Constructs a WaapConfigurationException.
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
   *   The default value to return if key doesn't exist.
   *
   * @return mixed
   *   The context value or default if not found.
   */
  public function getContextValue($key, $default = NULL) {
    return $this->context[$key] ?? $default;
  }

  /**
   * Creates exception for missing configuration key.
   *
   * @param string $key
   *   The missing configuration key.
   *
   * @return static
   *   The exception instance.
   */
  public static function missingConfigKey($key) {
    return new static(
      sprintf('Required WaaP configuration key is missing: %s', $key),
      self::ERROR_MISSING_CONFIG_KEY,
      ['config_key' => $key]
    );
  }

  /**
   * Creates exception for invalid configuration value.
   *
   * @param string $key
   *   The configuration key with invalid value.
   * @param mixed $value
   *   The invalid value.
   * @param string $expected
   *   Description of expected value format.
   *
   * @return static
   *   The exception instance.
   */
  public static function invalidConfigValue($key, $value, $expected = '') {
    $message = sprintf('Invalid WaaP configuration value for key %s', $key);
    if ($expected) {
      $message .= sprintf(': expected %s', $expected);
    }
    return new static(
      $message,
      self::ERROR_INVALID_CONFIG_VALUE,
      [
        'config_key' => $key,
        'value' => $value,
        'expected' => $expected,
      ]
    );
  }

  /**
   * Creates exception for schema validation failure.
   *
   * @param string $key
   *   The configuration key that failed validation.
   * @param string $reason
   *   The validation failure reason.
   *
   * @return static
   *   The exception instance.
   */
  public static function schemaValidationFailed($key, $reason) {
    return new static(
      sprintf('WaaP configuration schema validation failed for key %s: %s', $key, $reason),
      self::ERROR_SCHEMA_VALIDATION_FAILED,
      [
        'config_key' => $key,
        'reason' => $reason,
      ]
    );
  }

  /**
   * Creates exception for missing environment variable.
   *
   * @param string $var_name
   *   The missing environment variable name.
   *
   * @return static
   *   The exception instance.
   */
  public static function missingEnvVar($var_name) {
    return new static(
      sprintf('Required environment variable is not set: %s', $var_name),
      self::ERROR_MISSING_ENV_VAR,
      ['env_var' => $var_name]
    );
  }

  /**
   * Creates exception for configuration not writable.
   *
   * @param string $key
   *   The configuration key that cannot be written.
   *
   * @return static
   *   The exception instance.
   */
  public static function configNotWritable($key) {
    return new static(
      sprintf('WaaP configuration is not writable for key: %s', $key),
      self::ERROR_CONFIG_NOT_WRITABLE,
      ['config_key' => $key]
    );
  }

  /**
   * Creates exception for invalid configuration type.
   *
   * @param string $key
   *   The configuration key with wrong type.
   * @param string $actual_type
   *   The actual type found.
   * @param string $expected_type
   *   The expected type.
   *
   * @return static
   *   The exception instance.
   */
  public static function invalidConfigType($key, $actual_type, $expected_type) {
    return new static(
      sprintf('Invalid configuration type for key %s: expected %s, got %s', $key, $expected_type, $actual_type),
      self::ERROR_INVALID_CONFIG_TYPE,
      [
        'config_key' => $key,
        'actual_type' => $actual_type,
        'expected_type' => $expected_type,
      ]
    );
  }

  /**
   * Creates exception for value out of range.
   *
   * @param string $key
   *   The configuration key with out-of-range value.
   * @param mixed $value
   *   The out-of-range value.
   * @param string $range
   *   Description of valid range.
   *
   * @return static
   *   The exception instance.
   */
  public static function valueOutOfRange($key, $value, $range) {
    return new static(
      sprintf('WaaP configuration value out of range for key %s: %s (valid range: %s)', $key, $value, $range),
      self::ERROR_VALUE_OUT_OF_RANGE,
      [
        'config_key' => $key,
        'value' => $value,
        'range' => $range,
      ]
    );
  }

}
