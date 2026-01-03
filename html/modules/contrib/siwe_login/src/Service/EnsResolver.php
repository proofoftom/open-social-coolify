<?php

namespace Drupal\siwe_login\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Web3\Web3;
use Web3\Contract;
use kornrunner\Keccak;

/**
 * Service for resolving ENS names to/from Ethereum addresses.
 *
 * Supports both forward resolution (name → address) and reverse resolution
 * (address → name) with caching and automatic RPC failover.
 */
class EnsResolver {

  /**
   * The Web3 instance.
   *
   * @var \Web3\Web3
   */
  protected $web3;

  /**
   * Array of RPC provider URLs.
   *
   * @var array
   */
  protected $providerUrls;

  /**
   * Current provider index for failover.
   *
   * @var int
   */
  protected $currentProviderIndex = 0;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Cache TTL in seconds.
   *
   * @var int
   */
  protected $cacheTtl;

  /**
   * ENS Registry contract address.
   */
  const ENS_REGISTRY_ADDRESS = '0x00000000000C2E074eC69A0dFb2997BA6C7d2e1e';

  /**
   * Public Resolver contract address.
   */
  const PUBLIC_RESOLVER_ADDRESS = '0xF29100983E058B709F3D539b0c765937B804AC15';

  /**
   * ENS Registry ABI.
   */
  const ENS_REGISTRY_ABI = '[
    {
      "constant": true,
      "inputs": [
        {
          "internalType": "bytes32",
          "name": "node",
          "type": "bytes32"
        }
      ],
      "name": "resolver",
      "outputs": [
        {
          "internalType": "address",
          "name": "",
          "type": "address"
        }
      ],
      "payable": false,
      "stateMutability": "view",
      "type": "function"
    }
  ]';

  /**
   * Public Resolver ABI for addr() function.
   */
  const PUBLIC_RESOLVER_ABI = '[
    {
      "inputs": [
        {
          "internalType": "bytes32",
          "name": "node",
          "type": "bytes32"
        }
      ],
      "name": "addr",
      "outputs": [
        {
          "internalType": "address payable",
          "name": "",
          "type": "address"
        }
      ],
      "stateMutability": "view",
      "type": "function"
    }
  ]';

  /**
   * Reverse Resolver ABI for name() function.
   */
  const REVERSE_RESOLVER_ABI = '[
    {
      "inputs": [
        {
          "internalType": "bytes32",
          "name": "node",
          "type": "bytes32"
        }
      ],
      "name": "name",
      "outputs": [
        {
          "internalType": "string",
          "name": "",
          "type": "string"
        }
      ],
      "stateMutability": "view",
      "type": "function"
    }
  ]';

  /**
   * Constructs an EnsResolver object.
   *
   * @param \Drupal\siwe_login\Service\RpcProviderManager $provider_manager
   *   The RPC provider manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param int $cache_ttl
   *   Cache TTL in seconds (default: 3600).
   */
  public function __construct(
    RpcProviderManager $provider_manager,
    CacheBackendInterface $cache,
    LoggerChannelFactoryInterface $logger_factory,
    int $cache_ttl = 3600
  ) {
    $this->providerUrls = $provider_manager->getProviderUrls();
    $this->cache = $cache;
    $this->logger = $logger_factory->get('siwe_login');
    $this->cacheTtl = $cache_ttl;
    $this->initializeWeb3();
  }

  /**
   * Initializes the Web3 instance with the current provider.
   */
  protected function initializeWeb3(): void {
    if (empty($this->providerUrls[$this->currentProviderIndex])) {
      throw new \RuntimeException('No RPC provider available');
    }
    $this->web3 = new Web3($this->providerUrls[$this->currentProviderIndex]);
  }

  /**
   * Tries the next provider in the list.
   *
   * @return bool
   *   TRUE if there's another provider to try, FALSE otherwise.
   */
  protected function tryNextProvider(): bool {
    $this->currentProviderIndex++;
    if ($this->currentProviderIndex < count($this->providerUrls)) {
      $this->logger->warning('Switching to fallback RPC provider: @url', [
        '@url' => $this->providerUrls[$this->currentProviderIndex],
      ]);
      $this->initializeWeb3();
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Resets the provider index for future calls.
   */
  protected function resetProviderIndex(): void {
    $this->currentProviderIndex = 0;
    $this->initializeWeb3();
  }

  /**
   * Executes an operation with automatic failover.
   *
   * @param callable $operation
   *   The operation to execute.
   *
   * @return mixed
   *   The result of the operation.
   *
   * @throws \Exception
   *   If all providers fail.
   */
  protected function executeWithFailover(callable $operation) {
    $lastException = NULL;
    $startIndex = $this->currentProviderIndex;

    do {
      try {
        $result = $operation();
        // Reset for next call if we had to failover.
        if ($this->currentProviderIndex !== $startIndex) {
          $this->resetProviderIndex();
        }
        return $result;
      }
      catch (\Exception $e) {
        $lastException = $e;
        $this->logger->warning('RPC call failed on @url: @message', [
          '@url' => $this->providerUrls[$this->currentProviderIndex],
          '@message' => $e->getMessage(),
        ]);
      }
    } while ($this->tryNextProvider());

    // Reset for next call.
    $this->resetProviderIndex();
    throw $lastException;
  }

  /**
   * Resolves an ENS name to an Ethereum address (forward resolution).
   *
   * @param string $ens_name
   *   The ENS name to resolve.
   *
   * @return string|null
   *   The resolved Ethereum address or NULL if resolution fails.
   */
  public function resolveName(string $ens_name): ?string {
    // Check cache first.
    $cache_key = 'siwe_login:ens_forward:' . strtolower($ens_name);
    $cached = $this->cache->get($cache_key);
    if ($cached !== FALSE) {
      return $cached->data;
    }

    try {
      $address = $this->executeWithFailover(function () use ($ens_name) {
        return $this->doForwardResolution($ens_name);
      });

      // Cache result.
      $this->cache->set($cache_key, $address, time() + $this->cacheTtl);

      return $address;
    }
    catch (\Exception $e) {
      $this->logger->error('ENS forward resolution failed for @name: @message', [
        '@name' => $ens_name,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Performs the actual forward resolution.
   *
   * @param string $ens_name
   *   The ENS name to resolve.
   *
   * @return string|null
   *   The resolved Ethereum address or NULL.
   */
  protected function doForwardResolution(string $ens_name): ?string {
    // Convert ENS name to node hash.
    $node = $this->namehash($ens_name);

    // Get resolver address from ENS Registry.
    $resolver_address = $this->getResolver($node);

    // Check if resolver exists.
    if (empty($resolver_address) || strtolower($resolver_address) === '0x0000000000000000000000000000000000000000') {
      return NULL;
    }

    // Get Ethereum address from resolver.
    return $this->getAddressFromResolver($resolver_address, $node);
  }

  /**
   * Resolves an Ethereum address to its primary ENS name (reverse resolution).
   *
   * @param string $address
   *   The Ethereum address (with or without 0x prefix).
   *
   * @return string|null
   *   The primary ENS name or NULL if not found/not set.
   */
  public function resolveAddress(string $address): ?string {
    // Normalize address (lowercase, ensure 0x prefix).
    $address = strtolower($address);
    if (substr($address, 0, 2) !== '0x') {
      $address = '0x' . $address;
    }

    // Check cache first.
    $cache_key = 'siwe_login:ens_reverse:' . $address;
    $cached = $this->cache->get($cache_key);
    if ($cached !== FALSE) {
      return $cached->data;
    }

    try {
      $ens_name = $this->executeWithFailover(function () use ($address) {
        return $this->doReverseResolution($address);
      });

      // Verify forward resolution matches (security check).
      if ($ens_name !== NULL) {
        $forward_address = $this->resolveName($ens_name);
        if (empty($forward_address) || strtolower($forward_address) !== strtolower($address)) {
          $this->logger->warning('ENS forward verification failed: @ens resolves to @forward, not @address', [
            '@ens' => $ens_name,
            '@forward' => $forward_address ?? 'NULL',
            '@address' => $address,
          ]);
          $ens_name = NULL;
        }
      }

      // Cache result (including NULL to avoid repeated lookups).
      $this->cache->set($cache_key, $ens_name, time() + $this->cacheTtl);

      return $ens_name;
    }
    catch (\Exception $e) {
      $this->logger->error('ENS reverse resolution failed for @address: @message', [
        '@address' => $address,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Performs the actual reverse resolution RPC call.
   *
   * @param string $address
   *   The Ethereum address (normalized, with 0x prefix).
   *
   * @return string|null
   *   The ENS name or NULL if not found.
   */
  protected function doReverseResolution(string $address): ?string {
    // Construct reverse node: <address>.addr.reverse
    // Remove 0x prefix for reverse name.
    $reverse_name = substr($address, 2) . '.addr.reverse';
    $node = $this->namehash($reverse_name);

    // Get resolver for the reverse node.
    $resolver_address = $this->getResolver($node);

    if (empty($resolver_address) ||
        strtolower($resolver_address) === '0x0000000000000000000000000000000000000000') {
      return NULL;
    }

    // Call name() on the resolver.
    return $this->getNameFromResolver($resolver_address, $node);
  }

  /**
   * Gets the resolver address for a node from the ENS Registry.
   *
   * @param string $node
   *   The node hash.
   *
   * @return string|null
   *   The resolver address or NULL if not found.
   */
  protected function getResolver(string $node): ?string {
    $result = NULL;

    $contract = new Contract($this->web3->provider, self::ENS_REGISTRY_ABI);
    $contract->at(self::ENS_REGISTRY_ADDRESS)->call('resolver', $node, function ($err, $response) use (&$result) {
      if ($err) {
        throw new \Exception('Failed to get resolver: ' . $err->getMessage());
      }
      if (isset($response[0])) {
        $result = $response[0];
      }
    });

    return $result;
  }

  /**
   * Gets the Ethereum address from a resolver contract.
   *
   * @param string $resolver_address
   *   The resolver contract address.
   * @param string $node
   *   The node hash.
   *
   * @return string|null
   *   The Ethereum address or NULL if not found.
   */
  protected function getAddressFromResolver(string $resolver_address, string $node): ?string {
    $result = NULL;

    $contract = new Contract($this->web3->provider, self::PUBLIC_RESOLVER_ABI);
    $contract->at($resolver_address)->call('addr', $node, function ($err, $response) use (&$result) {
      if ($err) {
        throw new \Exception('Failed to get address from resolver: ' . $err->getMessage());
      }
      if (isset($response[0])) {
        $result = $response[0];
      }
    });

    return $result;
  }

  /**
   * Gets the ENS name from a reverse resolver contract.
   *
   * @param string $resolver_address
   *   The resolver contract address.
   * @param string $node
   *   The node hash.
   *
   * @return string|null
   *   The ENS name or NULL if not found.
   */
  protected function getNameFromResolver(string $resolver_address, string $node): ?string {
    $result = NULL;

    $contract = new Contract($this->web3->provider, self::REVERSE_RESOLVER_ABI);
    $contract->at($resolver_address)->call('name', $node, function ($err, $response) use (&$result) {
      if ($err) {
        throw new \Exception('Failed to get name from resolver: ' . $err->getMessage());
      }
      if (isset($response[0]) && !empty($response[0])) {
        $result = $response[0];
      }
    });

    return $result;
  }

  /**
   * Converts an ENS name to a node hash using the namehash algorithm.
   *
   * @param string $name
   *   The ENS name.
   *
   * @return string
   *   The node hash.
   */
  protected function namehash(string $name): string {
    if (empty($name)) {
      return '0x0000000000000000000000000000000000000000000000000000000000000000';
    }

    $node = '0x0000000000000000000000000000000000000000000000000000000000000000';

    // Split the name into labels and process in reverse order.
    $labels = explode('.', strtolower($name));
    $labels = array_reverse($labels);

    foreach ($labels as $label) {
      $node = '0x' . Keccak::hash(hex2bin(substr($node, 2)) . hex2bin(Keccak::hash($label, 256)), 256);
    }

    return $node;
  }

}
