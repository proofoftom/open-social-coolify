<?php

namespace Drupal\waap_login\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\user\UserAuthInterface;
use Drupal\waap_login\Service\WaapAuthService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for WaaP authentication endpoints.
 *
 * Handles HTTP endpoints for WaaP authentication including
 * verification, status checking, and logout functionality.
 */
class WaapAuthController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The WaaP authentication service.
   *
   * @var \Drupal\waap_login\Service\WaapAuthService
   */
  protected $authService;

  /**
   * The CSRF token generator.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected $csrfToken;

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The logger channel for WaaP login.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The user authentication service.
   *
   * @var \Drupal\user\UserAuthInterface
   */
  protected $userAuth;

  /**
   * Constructs a new WaapAuthController.
   *
   * @param \Drupal\waap_login\Service\WaapAuthService $auth_service
   *   The WaaP authentication service.
   * @param \Drupal\Core\Access\CsrfTokenGenerator $csrf_token
   *   The CSRF token generator.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\user\UserAuthInterface $user_auth
   *   The user authentication service.
   */
  public function __construct(
    WaapAuthService $auth_service,
    CsrfTokenGenerator $csrf_token,
    AccountProxyInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory,
    UserAuthInterface $user_auth
  ) {
    $this->authService = $auth_service;
    $this->csrfToken = $csrf_token;
    $this->currentUser = $current_user;
    $this->logger = $logger_factory->get('waap_login');
    $this->userAuth = $user_auth;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('waap_login.auth_service'),
      $container->get('csrf_token'),
      $container->get('current_user'),
      $container->get('logger.factory'),
      $container->get('user.auth')
    );
  }

  /**
   * Verify WaaP session and authenticate user.
   *
   * This endpoint handles POST requests containing WaaP authentication data.
   * It validates the request, checks flood control, validates the wallet
   * address, and authenticates the user.
   *
   * Expected POST payload:
   * {
   *   "address": "0x...",
   *   "loginType": "waap|injected|walletconnect",
   *   "sessionData": { ... },  // Optional WaaP session metadata
   *   "csrf_token": "..."  // CSRF token for validation
   * }
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with authentication result.
   */
  public function verify(Request $request): JsonResponse {
    try {
      // Get client IP for flood control.
      $identifier = $request->getClientIp();

      // Check flood control before processing.
      if (!$this->authService->isFloodAllowed($identifier)) {
        $this->logger->warning('Flood control triggered for IP: @ip', [
          '@ip' => $identifier,
        ]);
        return $this->errorResponse(
          'Too many authentication attempts. Please try again later.',
          'RATE_LIMIT_EXCEEDED',
          [],
          429
        );
      }

      // Parse JSON request body.
      $content = $request->getContent();
      $data = json_decode($content, TRUE);

      // Validate JSON parsing.
      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->warning('Invalid JSON received: @error', [
          '@error' => json_last_error_msg(),
        ]);
        return $this->errorResponse(
          'Invalid JSON format',
          'INVALID_JSON',
          ['details' => json_last_error_msg()],
          400
        );
      }

      // Validate required fields.
      if (empty($data['address'])) {
        return $this->errorResponse(
          'Wallet address is required',
          'MISSING_ADDRESS',
          [],
          400
        );
      }

      if (empty($data['loginType'])) {
        return $this->errorResponse(
          'Login type is required',
          'MISSING_LOGIN_TYPE',
          [],
          400
        );
      }

      // Validate login type value.
      $validLoginTypes = ['waap', 'injected', 'walletconnect'];
      if (!in_array($data['loginType'], $validLoginTypes, TRUE)) {
        return $this->errorResponse(
          'Invalid login type. Must be one of: ' . implode(', ', $validLoginTypes),
          'INVALID_LOGIN_TYPE',
          ['received' => $data['loginType']],
          400
        );
      }

      // Validate CSRF token if provided.
      if (isset($data['csrf_token'])) {
        if (!$this->authService->validateCsrfToken($data['csrf_token'], 'waap_verify')) {
          $this->logger->warning('Invalid CSRF token for verification attempt');
          return $this->errorResponse(
            'Invalid CSRF token',
            'CSRF_INVALID',
            [],
            403
          );
        }
      }

      // Sanitize input data.
      $data['address'] = trim($data['address']);
      $data['loginType'] = trim($data['loginType']);
      if (isset($data['sessionData'])) {
        $data['sessionData'] = $this->sanitizeSessionData($data['sessionData']);
      }

      // Call authentication service.
      $result = $this->authService->authenticate($data);

      // Check for authentication error.
      if ($result['error']) {
        // Register flood event for failed attempt.
        $this->authService->registerFloodEvent($identifier);

        $this->logger->warning('Authentication failed: @error', [
          '@error' => $result['error'],
        ]);

        return $this->errorResponse(
          $result['error'],
          'AUTH_FAILED',
          [],
          401
        );
      }

      // Check for multi-step authentication redirect.
      if ($result['redirect']) {
        $this->logger->info('Multi-step auth redirect: @redirect for user @uid', [
          '@redirect' => $result['redirect'],
          '@uid' => $result['user']->id(),
        ]);

        return new JsonResponse([
          'success' => TRUE,
          'message' => $this->getRedirectMessage($result['redirect']),
          'next_step' => $this->getNextStep($result['redirect']),
          'verification_url' => $result['redirect'],
          'user' => $this->formatUser($result['user']),
        ], 200);
      }

      // Authentication successful - log in the user.
      $user = $result['user'];
      $this->userAuth->finalizeLogin($user);

      // Register successful flood event.
      $this->authService->registerFloodEvent($identifier);

      $this->logger->info('User @uid successfully authenticated via WaaP', [
        '@uid' => $user->id(),
      ]);

      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Authentication successful',
        'user' => $this->formatUser($user),
        'next_step' => NULL,
      ], 200);
    }
    catch (\Exception $e) {
      $this->logger->error('Unexpected error in verify endpoint: @message', [
        '@message' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);

      return $this->errorResponse(
        'An unexpected error occurred during authentication',
        'INTERNAL_ERROR',
        [],
        500
      );
    }
  }

  /**
   * Get current WaaP authentication status.
   *
   * Returns the current user's WaaP authentication state including
   * session validation and user information.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with authentication status.
   */
  public function getStatus(Request $request): JsonResponse {
    try {
      // Check if user is authenticated.
      if (!$this->currentUser->isAuthenticated()) {
        return new JsonResponse([
          'authenticated' => FALSE,
          'message' => 'No active session',
        ], 200);
      }

      $uid = $this->currentUser->id();
      $user = $this->entityTypeManager()->getStorage('user')->load($uid);

      if (!$user) {
        $this->logger->warning('User not found for UID @uid', ['@uid' => $uid]);
        return new JsonResponse([
          'authenticated' => FALSE,
          'message' => 'User session invalid',
        ], 200);
      }

      // Get WaaP session metadata.
      $sessionValidator = $this->authService->getSessionValidator();
      $sessionData = $sessionValidator->getSession($uid);

      // Get wallet address from user field.
      $address = $user->get('field_ethereum_address')->value ?? NULL;

      $response = [
        'authenticated' => TRUE,
        'user' => $this->formatUser($user),
      ];

      // Add WaaP-specific information if available.
      if ($sessionData) {
        $response['waapMethod'] = $sessionData['login_type'] ?? 'unknown';
        $response['loginMethod'] = $sessionData['login_method'] ?? 'unknown';
        $response['provider'] = $sessionData['provider'] ?? NULL;
        $response['sessionTimestamp'] = $sessionData['timestamp'] ?? NULL;

        // Check if session is expired.
        $sessionTtl = $this->authService->getSessionTtl();
        $sessionAge = time() - ($sessionData['timestamp'] ?? 0);
        $response['sessionValid'] = $sessionAge < $sessionTtl;
      }
      else {
        $response['waapMethod'] = 'unknown';
        $response['sessionValid'] = FALSE;
      }

      // Add wallet address if available.
      if ($address) {
        $response['user']['address'] = $address;
      }

      return new JsonResponse($response, 200);
    }
    catch (\Exception $e) {
      $this->logger->error('Unexpected error in getStatus endpoint: @message', [
        '@message' => $e->getMessage(),
      ]);

      return $this->errorResponse(
        'Failed to retrieve authentication status',
        'INTERNAL_ERROR',
        [],
        500
      );
    }
  }

  /**
   * Handle WaaP logout.
   *
   * Clears WaaP session data, logs the user out, and returns
   * a success response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with logout result.
   */
  public function logout(Request $request): JsonResponse {
    try {
      // Check if user is authenticated.
      if (!$this->currentUser->isAuthenticated()) {
        return new JsonResponse([
          'success' => TRUE,
          'message' => 'No active session to logout',
        ], 200);
      }

      $uid = $this->currentUser->id();

      // Clear WaaP session data.
      $sessionValidator = $this->authService->getSessionValidator();
      $sessionValidator->clearSession($uid);

      // Log the user out.
      user_logout();

      $this->logger->info('User @uid logged out from WaaP', [
        '@uid' => $uid,
      ]);

      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Logout successful',
      ], 200);
    }
    catch (\Exception $e) {
      $this->logger->error('Unexpected error in logout endpoint: @message', [
        '@message' => $e->getMessage(),
      ]);

      // Even if there's an error, try to return success to allow logout.
      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Logout completed',
      ], 200);
    }
  }

  /**
   * Formats user data for JSON response.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   *
   * @return array
   *   Formatted user data array.
   */
  protected function formatUser($user): array {
    return [
      'uid' => (int) $user->id(),
      'name' => $user->getAccountName(),
      'email' => $user->getEmail(),
    ];
  }

  /**
   * Sanitizes session data array.
   *
   * Removes potentially dangerous keys and values from session data.
   *
   * @param array $sessionData
   *   The session data to sanitize.
   *
   * @return array
   *   Sanitized session data.
   */
  protected function sanitizeSessionData(array $sessionData): array {
    $allowedKeys = ['loginMethod', 'provider', 'timestamp', 'metadata'];
    $sanitized = [];

    foreach ($allowedKeys as $key) {
      if (isset($sessionData[$key])) {
        $sanitized[$key] = is_string($sessionData[$key])
          ? htmlspecialchars($sessionData[$key], ENT_QUOTES, 'UTF-8')
          : $sessionData[$key];
      }
    }

    return $sanitized;
  }

  /**
   * Gets a user-friendly message for redirect.
   *
   * @param string $redirect
   *   The redirect URL.
   *
   * @return string
   *   The redirect message.
   */
  protected function getRedirectMessage(string $redirect): string {
    $messages = [
      '/waap/email-verification' => 'Email verification required',
      '/waap/create-username' => 'Username creation required',
    ];

    return $messages[$redirect] ?? 'Additional verification required';
  }

  /**
   * Gets the next step identifier from redirect URL.
   *
   * @param string $redirect
   *   The redirect URL.
   *
   * @return string|null
   *   The next step identifier or NULL.
   */
  protected function getNextStep(string $redirect): ?string {
    $steps = [
      '/waap/email-verification' => 'email_verification',
      '/waap/create-username' => 'username_creation',
    ];

    return $steps[$redirect] ?? NULL;
  }

  /**
   * Creates an error JSON response.
   *
   * @param string $message
   *   The error message.
   * @param string $code
   *   The error code.
   * @param array $details
   *   Additional error details.
   * @param int $status
   *   The HTTP status code.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The error response.
   */
  protected function errorResponse(
    string $message,
    string $code,
    array $details = [],
    int $status = 400
  ): JsonResponse {
    $response = [
      'success' => FALSE,
      'error' => $message,
      'code' => $code,
      'timestamp' => date('c'),
    ];

    if (!empty($details)) {
      $response['details'] = $details;
    }

    return new JsonResponse($response, $status);
  }

}
