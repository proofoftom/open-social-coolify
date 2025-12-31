<?php

namespace Drupal\waap_login\Service;

use Drupal\Core\Session\SessionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;

/**
 * Manages WaaP session validation and persistence.
 *
 * This service handles WaaP session metadata storage,
 * validation, and expiration using Drupal's key-value store.
 */
class WaapSessionValidator {

  /**
   * The session service.
   *
   * @var \Symfony\Component\HttpFoundation\Session\SessionInterface
   */
  protected $session;

  /**
   * The logger channel for WaaP login.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The WaaP login configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The key-value store for session metadata.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface
   */
  protected $keyValueStore;

  /**
   * Constructs a new WaapSessionValidator.
   *
   * @param \Symfony\Component\HttpFoundation\SessionInterface $session
   *   The session service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface $keyvalue
   *   The key-value store.
   */
  public function __construct(
    SessionInterface $session,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    KeyValueStoreExpirableInterface $keyvalue
  ) {
    $this->session = $session;
    $this->logger = $logger_factory->get('waap_login');
    $this->config = $config_factory->get('waap_login.settings');
    $this->keyValueStore = $keyvalue->get('waap_login.sessions');
  }

  /**
   * Validates WaaP session data.
   *
   * This method validates that session data contains required
   * fields and is not expired.
   *
   * @param array $sessionData
   *   Session data to validate.
   *
   * @return bool
   *   TRUE if session is valid, FALSE otherwise.
   */
  public function validateSession(array $sessionData): bool {
    try {
      // Check if session data is empty.
      if (empty($sessionData)) {
        $this->logger->warning('Empty session data provided for validation');
        return FALSE;
      }

      // Check for required fields.
      if (!isset($sessionData['login_type'])) {
        $this->logger->warning('Session data missing login_type field');
        return FALSE;
      }

      if (!isset($sessionData['timestamp'])) {
        $this->logger->warning('Session data missing timestamp field');
        return FALSE;
      }

      // Check for valid login types.
      $validLoginTypes = ['waap', 'injected', 'walletconnect'];
      if (!in_array($sessionData['login_type'], $validLoginTypes)) {
        $this->logger->warning('Invalid login_type in session data: @type', [
          '@type' => $sessionData['login_type'],
        ]);
        return FALSE;
      }

      // Check if session has expired.
      if ($this->isSessionExpired($sessionData['timestamp'])) {
        $this->logger->warning('WaaP session has expired for timestamp @timestamp', [
          '@timestamp' => $sessionData['timestamp'],
        ]);
        return FALSE;
      }

      $this->logger->debug('WaaP session validated successfully', [
        '@login_type' => $sessionData['login_type'],
        '@login_method' => $sessionData['login_method'] ?? 'unknown',
      ]);

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('WaaP session validation failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Stores WaaP session metadata.
   *
   * This method stores session metadata in the key-value store
   * with an expiration time based on session_ttl configuration.
   *
   * @param int $uid
   *   The user ID.
   * @param array $sessionData
   *   Session metadata to store.
   */
  public function storeSession(int $uid, array $sessionData): void {
    try {
      $sessionTtl = $this->config->get('session_ttl');
      $expiration = time() + $sessionTtl;

      $this->keyValueStore->set($uid, [
        'login_type' => $sessionData['login_type'] ?? 'unknown',
        'login_method' => $sessionData['login_method'] ?? 'unknown',
        'provider' => $sessionData['provider'] ?? NULL,
        'timestamp' => $sessionData['timestamp'] ?? time(),
        'expires' => $expiration,
      ]);

      $this->logger->info('Stored WaaP session for user @uid', [
        '@uid' => $uid,
        '@login_type' => $sessionData['login_type'] ?? 'unknown',
        '@expires' => $expiration,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to store WaaP session for user @uid: @message', [
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Gets WaaP session metadata.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array|null
   *   Session metadata array, or NULL if not found.
   */
  public function getSession(int $uid): ?array {
    try {
      $session = $this->keyValueStore->get($uid);

      if (!$session) {
        return NULL;
      }

      // Check if session has expired.
      if (isset($session['expires']) && $session['expires'] < time()) {
        $this->logger->info('WaaP session for user @uid has expired, cleaning up', [
          '@uid' => $uid,
          '@expires' => $session['expires'],
        ]);
        $this->clearSession($uid);
        return NULL;
      }

      return $session;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get WaaP session for user @uid: @message', [
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Clears WaaP session metadata.
   *
   * This method removes session metadata from the key-value store.
   *
   * @param int $uid
   *   The user ID.
   */
  public function clearSession(int $uid): void {
    try {
      $this->keyValueStore->delete($uid);

      $this->logger->info('Cleared WaaP session for user @uid', [
        '@uid' => $uid,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to clear WaaP session for user @uid: @message', [
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Checks if a session has expired.
   *
   * @param int $timestamp
   *   The session timestamp.
   *
   * @return bool
   *   TRUE if session has expired, FALSE otherwise.
   */
  protected function isSessionExpired(int $timestamp): bool {
    $sessionTtl = $this->config->get('session_ttl');
    $expiration = $timestamp + $sessionTtl;

    return $expiration < time();
  }

}
