<?php

namespace Drupal\siwe_login\Service;

use Web3\Web3;
use Web3\Contract;
use kornrunner\Keccak;

/**
 * Service for resolving ENS names to Ethereum addresses.
 */
class EnsResolver {

  /**
   * The Web3 instance.
   *
   * @var \Web3\Web3
   */
  protected $web3;

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
   * Public Resolver ABI.
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
   * Constructs an EnsResolver object.
   *
   * @param string $provider_url
   *   The Ethereum provider URL.
   */
  public function __construct(string $provider_url) {
    $this->web3 = new Web3($provider_url);
  }

  /**
   * Resolves an ENS name to an Ethereum address.
   *
   * @param string $ens_name
   *   The ENS name to resolve.
   *
   * @return string|null
   *   The resolved Ethereum address or NULL if resolution fails.
   */
  public function resolveName(string $ens_name): ?string {
    try {
      // Convert ENS name to node hash.
      $node = $this->namehash($ens_name);

      // Get resolver address from ENS Registry.
      $resolver_address = $this->getResolver($node);

      // Check if resolver exists.
      if (empty($resolver_address) || strtolower($resolver_address) === '0x0000000000000000000000000000000000000000') {
        return NULL;
      }

      // Get Ethereum address from resolver.
      $eth_address = $this->getAddressFromResolver($resolver_address, $node);

      return $eth_address;
    }
    catch (\Exception $e) {
      // Log the error for debugging.
      \Drupal::logger('siwe_login')->error('ENS resolution failed for @name: @message', [
        '@name' => $ens_name,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
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
  private function getResolver(string $node): ?string {
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
  private function getAddressFromResolver(string $resolver_address, string $node): ?string {
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
   * Converts an ENS name to a node hash using the namehash algorithm.
   *
   * @param string $name
   *   The ENS name.
   *
   * @return string
   *   The node hash.
   */
  private function namehash(string $name): string {
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