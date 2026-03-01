<?php

namespace Drupal\siwe_login\Service;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Elliptic\EC;
use kornrunner\Keccak;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\siwe_login\Exception\InvalidSiweMessageException;

/**
 * Service for validating SIWE messages.
 */
class SiweMessageValidator {

  /**
   * The logger channel for SIWE login.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The SIWE login configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The cache backend for nonce storage.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Constructs a SiweMessageValidator object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The default cache backend.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    TimeInterface $time,
    ConfigFactoryInterface $config_factory,
    CacheBackendInterface $cache,
  ) {
    $this->logger = $logger_factory->get('siwe_login');
    $this->time = $time;
    $this->config = $config_factory->get('siwe_login.settings');
    $this->cache = $cache;
  }

  /**
   * Creates a new instance of SiweMessageValidator.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   *
   * @return static
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('logger.factory'),
      $container->get('datetime.time'),
      $container->get('config.factory'),
      $container->get('cache.default')
    );
  }

  /**
   * Validates a SIWE message and signature.
   *
   * @param array $message_data
   *   Array containing:
   *   - message: The SIWE message string
   *   - signature: The signature
   *   - address: The Ethereum address
   *   - nonce: The nonce
   *   - issued_at: The issued timestamp
   *   - expiration_time: The expiration timestamp.
   *
   * @return bool
   *   TRUE if valid, throws exception otherwise.
   *
   * @throws \Drupal\siwe_login\Exception\InvalidSiweMessageException
   */
  public function validateMessage(array $message_data): bool {
    try {
      // Parse the SIWE message.
      $parsed_message = $this->parseSiweMessage($message_data['message']);

      // Merge parsed message data with provided data.
      $message_data = array_merge($parsed_message, $message_data);

      // Validate message structure.
      $this->validateMessageStructure($message_data);

      // Verify signature using Ethereum standards.
      $this->verifySignature(
        $message_data['message'],
        $message_data['signature'],
        $message_data['address']
      );

      // Validate temporal constraints.
      $this->validateTimestamps($message_data);

      // Validate nonce.
      $this->validateNonce($message_data['nonce']);

      // Validate domain.
      $this->validateDomain($message_data);

      // Validate ENS name if present in resources and ENS validation is enabled.
      if ($this->config->get('enable_ens_validation') && isset($message_data['resources']) && !empty($message_data['resources'][0])) {
        // Extract ENS name from resources (format: ens:{ens-name})
        $ens_resource = $message_data['resources'][0];
        if (strpos($ens_resource, 'ens:') === 0) {
          $ens_name = substr($ens_resource, strlen('ens:'));
          $this->validateENS($message_data['address'], $ens_name);
        }
        else {
          throw new InvalidSiweMessageException('Invalid ENS resource format');
        }
      }

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error(
        'SIWE message validation failed: @message', [
          '@message' => $e->getMessage(),
        ]
      );
      throw new InvalidSiweMessageException($e->getMessage());
    }
  }

  /**
   * Validates the structure of a SIWE message.
   */
  protected function validateMessageStructure(array $message_data): void {
    $required_fields = ['domain', 'address', 'uri', 'version', 'nonce', 'issuedAt'];
    foreach ($required_fields as $field) {
      if (empty($message_data[$field])) {
        throw new InvalidSiweMessageException("Missing required field: $field");
      }
    }
  }

  /**
   * Verifies the cryptographic signature.
   */
  protected function verifySignature(string $message, string $signature, string $address): bool {
    // Prepare the message for hashing according to EIP-191.
    $message_prefix = "\x19Ethereum Signed Message:\n" . strlen($message);
    $prefixed_message = $message_prefix . $message;

    // Hash the message using Keccak-256.
    $message_hash = Keccak::hash($prefixed_message, 256);

    // Remove '0x' prefix if present.
    if (substr($signature, 0, 2) === '0x') {
      $signature = substr($signature, 2);
    }

    // Extract signature components.
    $r = substr($signature, 0, 64);
    $s = substr($signature, 64, 64);
    $v = hexdec(substr($signature, 128, 2));

    // Initialize ECDSA with secp256k1 curve.
    $ec = new EC('secp256k1');
    $sign = ['r' => $r, 's' => $s, 'v' => $v];

    // Calculate recovery ID.
    $recid = $v - 27;
    if ($recid < 0 || $recid > 3) {
      throw new InvalidSiweMessageException('Invalid recovery ID: ' . $v);
    }

    // Recover public key from signature.
    $pubKey = $ec->recoverPubKey($message_hash, $sign, $recid);

    // Derive Ethereum address from public key
    // Remove the first byte (0x04) which indicates uncompressed key.
    $pubKeyBin = hex2bin($pubKey->encode('hex'));
    $pubKeyWithoutPrefix = substr($pubKeyBin, 1);

    // Hash the public key and take the last 20 bytes.
    $address_hash = Keccak::hash($pubKeyWithoutPrefix, 256);
    $recovered_address = '0x' . substr($address_hash, 24);

    // Compare recovered address with provided address (case-insensitive)
    if (strtolower($recovered_address) !== strtolower($address)) {
      throw new InvalidSiweMessageException('Signature verification failed');
    }

    return TRUE;
  }

  /**
   * Validates message timestamps.
   */
  protected function validateTimestamps(array $message_data): void {
    $current_time = $this->time->getCurrentTime();

    // Check if message is not from the future (clock skew tolerance of 30
    // seconds)
    if (isset($message_data['issuedAt'])) {
      $issued_at = strtotime($message_data['issuedAt']);
      if ($issued_at > $current_time + 30) {
        throw new InvalidSiweMessageException('Message issued in the future');
      }
    }

    // Check if message is not too old (5 minutes)
    if (isset($message_data['issuedAt'])) {
      $issued_at = strtotime($message_data['issuedAt']);
      if ($current_time - $issued_at > 300) {
        throw new InvalidSiweMessageException('Message is too old');
      }
    }

    if (isset($message_data['expirationTime'])) {
      $expiration = strtotime($message_data['expirationTime']);
      if ($current_time > $expiration) {
        throw new InvalidSiweMessageException('Message has expired');
      }
    }

    if (isset($message_data['notBefore'])) {
      $not_before = strtotime($message_data['notBefore']);
      if ($current_time < $not_before) {
        throw new InvalidSiweMessageException('Message not yet valid');
      }
    }
  }

  /**
   * Validates nonce for replay attack prevention.
   */
  protected function validateNonce(string $nonce): void {
    // Check nonce against stored values in cache.
    $nonce_key = 'siwe_nonce_lookup:' . $nonce;
    $cached_client = $this->cache->get($nonce_key);

    if (!$cached_client) {
      throw new InvalidSiweMessageException('Invalid or expired nonce');
    }
  }

  /**
   * Validates domain matches expected domain(s).
   */
  protected function validateDomain(array $message_data): void {
    // This config field may be updated by the SIWE Server module to include
    // multiple domains.
    $expected_domain = $this->config->get('expected_domain');

    // Handle comma-separated domains.
    $expected_domains = array_map('trim', explode(',', $expected_domain));

    // Filter out empty domains.
    $expected_domains = array_filter($expected_domains);

    // Log the expected and actual domain for debugging.
    $this->logger->debug(
      'Validating domain. Expected: @expected, Actual: @actual', [
        '@expected' => implode(', ', $expected_domains),
        '@actual' => $message_data['domain'],
      ]
    );

    // Check if the domain matches exactly.
    if (in_array($message_data['domain'], $expected_domains)) {
      return;
    }

    throw new InvalidSiweMessageException('Invalid domain');
  }

  /**
   * Validates that an ENS name resolves to the signing address.
   *
   * @param string $address
   *   The Ethereum address that signed the message.
   * @param string $ens_name
   *   The ENS name to validate.
   *
   * @throws \Drupal\siwe_login\Exception\InvalidSiweMessageException
   */
  protected function validateENS(string $address, string $ens_name): void {
    // Initialize ENS resolver with Ethereum provider.
    $ethereum_provider_url = $this->config->get('ethereum_provider_url') ?: '';
    $ens_resolver = new EnsResolver($ethereum_provider_url);

    // Resolve ENS name to Ethereum address.
    $resolved_address = $ens_resolver->resolveName($ens_name);

    // Check if resolution was successful.
    if (empty($resolved_address)) {
      throw new InvalidSiweMessageException('Failed to resolve ENS name: ' . $ens_name);
    }

    // Compare resolved address with signing address (case-insensitive).
    if (strtolower($resolved_address) !== strtolower($address)) {
      throw new InvalidSiweMessageException('ENS name does not resolve to signing address');
    }

    // Log successful ENS validation.
    // $this->logger->info('ENS name @ens resolved to address @address', [
    //   '@ens' => $ens_name,
    //   '@address' => $resolved_address,
    // ]);
  }

  /**
   * Parses a SIWE message according to ERC-4361 format.
   */
  public function parseSiweMessage(string $message): array {
    $lines = explode("\n", $message);
    $parsed = [];

    // Parse the first line: domain wants you to sign in.
    $first_line = array_shift($lines);
    if (preg_match("/^(.+) wants you to sign in with your Ethereum account:/", $first_line, $matches)) {
      $parsed['domain'] = $matches[1];
    }

    // Parse the address (second line) and convert to checksum format.
    $address = trim(array_shift($lines));
    $parsed['address'] = $this->toChecksumAddress($address);

    // Skip empty line if present.
    if (isset($lines[0]) && trim($lines[0]) === '') {
      array_shift($lines);
    }

    // Parse statement if present (optional, can be multiline until we hit a
    // field)
    $statement_lines = [];
    while (!empty($lines)) {
      $line = $lines[0];
      // Check if this line is a field (contains ': ')
      if (strpos($line, ': ') !== FALSE
        && preg_match("/^(URI|Version|Chain ID|Nonce|Issued At|Expiration Time|Not Before|Request ID|Resources):/", $line)
      ) {
        break;
      }
      $statement_lines[] = array_shift($lines);
    }

    if (!empty($statement_lines)) {
      $parsed['statement'] = trim(implode("\n", $statement_lines));
    }

    // Parse the remaining fields.
    foreach ($lines as $line) {
      $line = trim($line);
      if (empty($line)) {
        continue;
      }

      // Check if this line is a field header (may or may not have a value)
      if (preg_match(
        "/^(URI|Version|Chain ID|Nonce|Issued At|Expiration Time|Not Before|Request ID|Resources):(.*)$/",
        $line,
        $matches
      )
      ) {
        $key = trim($matches[1]);
        $value = trim($matches[2]);

        switch ($key) {
          case 'URI':
            $parsed['uri'] = $value;
            break;

          case 'Version':
            $parsed['version'] = $value;
            break;

          case 'Chain ID':
            $parsed['chainId'] = $value;
            break;

          case 'Nonce':
            $parsed['nonce'] = $value;
            break;

          case 'Issued At':
            $parsed['issuedAt'] = $value;
            break;

          case 'Expiration Time':
            $parsed['expirationTime'] = $value;
            break;

          case 'Not Before':
            $parsed['notBefore'] = $value;
            break;

          case 'Request ID':
            $parsed['requestId'] = $value;
            break;

          case 'Resources':
            // Resources field header - always initialize empty array.
            $parsed['resources'] = [];
            break;
        }
      }
      elseif (isset($parsed['resources']) && strpos($line, '- ') === 0) {
        // Resource lines always start with "- ".
        // Remove "- " prefix.
        $parsed['resources'][] = trim(substr($line, 2));
      }
    }

    return $parsed;
  }

  /**
   * Converts an Ethereum address to EIP-55 checksum format.
   */
  private function toChecksumAddress(string $address): string {
    $address = strtolower(substr($address, 0, 2) === '0x' ? substr($address, 2) : $address);
    $hash = Keccak::hash($address, 256);
    $address = str_split($address);
    $hash = str_split($hash);

    for ($i = 0; $i < count($address); $i++) {
      // Convert to uppercase if the corresponding hex character in the hash
      // is >= 8.
      if (hexdec($hash[$i]) >= 8) {
        $address[$i] = strtoupper($address[$i]);
      }
    }

    return '0x' . implode('', $address);
  }

}
