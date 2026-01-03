<?php

namespace Drupal\siwe_login\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Manages Ethereum RPC provider URLs with fallback support.
 *
 * Provides a list of RPC endpoints for ENS lookups, with automatic
 * fallback to free public endpoints when custom providers are not configured.
 */
class RpcProviderManager {

  /**
   * Default free public Ethereum mainnet RPC endpoints.
   *
   * These endpoints are used as fallbacks when no custom provider is configured.
   * They are suitable for occasional ENS lookups but have rate limits.
   */
  const DEFAULT_ENDPOINTS = [
    'https://eth.llamarpc.com',
    'https://ethereum.publicnode.com',
    'https://rpc.ankr.com/eth',
    'https://cloudflare-eth.com',
  ];

  /**
   * The SIWE login configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Constructs an RpcProviderManager.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->config = $config_factory->get('siwe_login.settings');
  }

  /**
   * Gets the list of RPC provider URLs in priority order.
   *
   * Returns URLs in this order:
   * 1. Primary URL from config (if set)
   * 2. Additional fallback URLs from config
   * 3. Default public endpoints
   *
   * @return array
   *   Array of RPC endpoint URLs.
   */
  public function getProviderUrls(): array {
    $urls = [];

    // Primary URL from config (may be custom Alchemy/Infura).
    $primary = $this->config->get('ethereum_provider_url');
    if (!empty($primary) && $this->isValidUrl($primary)) {
      $urls[] = $primary;
    }

    // Additional fallback URLs from config.
    $fallbacks = $this->config->get('ethereum_fallback_urls') ?? [];
    foreach ($fallbacks as $url) {
      if (!empty($url) && $this->isValidUrl($url) && !in_array($url, $urls)) {
        $urls[] = $url;
      }
    }

    // Add default endpoints as final fallbacks.
    foreach (self::DEFAULT_ENDPOINTS as $url) {
      if (!in_array($url, $urls)) {
        $urls[] = $url;
      }
    }

    return $urls;
  }

  /**
   * Validates a URL format.
   *
   * @param string $url
   *   The URL to validate.
   *
   * @return bool
   *   TRUE if the URL is valid, FALSE otherwise.
   */
  protected function isValidUrl(string $url): bool {
    return filter_var($url, FILTER_VALIDATE_URL) !== FALSE
      && (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0);
  }

}
