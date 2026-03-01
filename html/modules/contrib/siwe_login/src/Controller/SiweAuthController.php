<?php

namespace Drupal\siwe_login\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\siwe_login\Service\SiweAuthService;

/**
 * Controller for SIWE authentication endpoints.
 */
class SiweAuthController extends ControllerBase {

  /**
   * The SIWE authentication service.
   *
   * @var \Drupal\siwe_login\Service\SiweAuthService
   */
  protected $siweAuthService;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The private tempstore.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempstorePrivate;

  public function __construct(
    SiweAuthService $siwe_auth_service,
    ConfigFactoryInterface $config_factory,
    CacheBackendInterface $cache,
    PrivateTempStoreFactory $tempstore_private,
  ) {
    $this->siweAuthService = $siwe_auth_service;
    $this->configFactory = $config_factory;
    $this->cache = $cache;
    $this->tempstorePrivate = $tempstore_private;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('siwe_login.auth_service'),
      $container->get('config.factory'),
      $container->get('cache.default'),
      $container->get('tempstore.private')
    );
  }

  /**
   * Generates a nonce for SIWE.
   */
  public function getNonce(Request $request): JsonResponse {
    try {
      $nonce = $this->siweAuthService->generateNonce();

      // Store nonce in cache with a 5-minute TTL (300 seconds)
      $ttl = $this->configFactory->get('siwe_login.settings')->get('nonce_ttl');
      $this->cache->set('siwe_nonce_lookup:' . $nonce, TRUE, time() + $ttl);

      return new JsonResponse(
        [
          'nonce' => $nonce,
          'issued_at' => date('c'),
        ]
      );
    }
    catch (\Exception $e) {
      return new JsonResponse(
        [
          'error' => 'Failed to generate nonce',
        ],
        500
      );
    }
  }

  /**
   * Verifies SIWE message and authenticates user.
   */
  public function verify(Request $request): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE);

      if (!$data) {
        throw new \InvalidArgumentException('Invalid request data');
      }

      // Validate the SIWE message first.
      $is_valid = $this->siweAuthService->getMessageValidator()->validateMessage($data);

      if (!$is_valid) {
        return new JsonResponse(
          [
            'error' => 'Invalid SIWE message',
          ],
          400
        );
      }

      // Check if email verification is required.
      if ($this->siweAuthService->isEmailVerificationRequired()) {
        // Check if user exists.
        $user_manager = $this->siweAuthService->getUserManager();
        $user = $user_manager->findUserByAddress($data['address']);

        // If user doesn't exist, redirect to email verification form.
        if (!$user) {
          // Store the SIWE data in tempstore for later use.
          $tempstore = $this->tempstorePrivate->get('siwe_login');
          $tempstore->set('pending_siwe_data', $data);

          return new JsonResponse(
            [
              'success' => TRUE,
              'redirect' => '/siwe/email-verification',
            ]
          );
        }

        // If user exists but doesn't have an email or has a temporary email,
        // redirect to email verification form.
        if ($user && (empty($user->getEmail()) ||
          strpos($user->getEmail(), '@ethereum.local') !== FALSE)) {
          // Store the SIWE data in tempstore for later use.
          $tempstore = $this->tempstorePrivate->get('siwe_login');
          $tempstore->set('pending_siwe_data', $data);

          return new JsonResponse(
            [
              'success' => TRUE,
              'redirect' => '/siwe/email-verification',
            ]
          );
        }
      }

      // Check if ENS/username is required.
      if ($this->siweAuthService->isEnsOrUsernameRequired()) {
        // Extract ENS name from the raw message.
        $ensName = NULL;
        if (isset($data['message'])) {
          try {
            $validator = $this->siweAuthService->getMessageValidator();
            $parsed = $validator->parseSiweMessage($data['message']);

            if (isset($parsed['resources']) && !empty($parsed['resources'])) {
              foreach ($parsed['resources'] as $resource) {
                if (strpos($resource, 'ens:') === 0) {
                  // Remove 'ens:' prefix.
                  $ensName = substr($resource, 4);
                  break;
                }
              }
            }
          }
          catch (\Exception $e) {
            $this->getLogger('siwe_login')->warning(
              'Failed to extract ENS name from SIWE message: @message',
              [
                '@message' => $e->getMessage(),
              ]
            );
          }
        }

        // If user doesn't have an ENS name, redirect to username creation form.
        if (!$ensName) {
          // Check if user exists.
          $user_manager = $this->siweAuthService->getUserManager();
          $user = $user_manager->findUserByAddress($data['address']);

          // If user doesn't exist or has a generated username,
          // redirect to username creation form.
          if (!$user ||
            $user_manager->isGeneratedUsername(
              $user->getAccountName(),
              $data['address']
            )) {
            // Store the SIWE data in tempstore for later use.
            $tempstore = $this->tempstorePrivate->get('siwe_login');
            $tempstore->set('pending_siwe_data', $data);

            return new JsonResponse(
              [
                'success' => TRUE,
                'redirect' => '/siwe/create-username',
              ]
            );
          }
        }
      }

      // Verify SIWE message and authenticate user.
      $user = $this->siweAuthService->authenticate($data);

      if ($user) {
        // User authenticated successfully.
        user_login_finalize($user);

        $response_data = [
          'success' => TRUE,
          'user' => [
            'uid' => $user->id(),
            'name' => $user->getAccountName(),
            'address' => $user->get('field_ethereum_address')->value,
          ],
        ];

        // Allow other modules to alter the response (e.g., add redirect)
        $this->moduleHandler()->invokeAll('siwe_login_response_alter', [&$response_data, $user]);

        return new JsonResponse($response_data);
      }

      return new JsonResponse(
        [
          'error' => 'Authentication failed',
        ],
        401
      );
    }
    catch (\Exception $e) {
      $this->getLogger('siwe_login')->error(
        'SIWE verification failed: @message',
        [
          '@message' => $e->getMessage(),
        ]
      );

      return new JsonResponse(
        [
          'error' => $e->getMessage(),
        ],
        400
      );
    }
  }

}
