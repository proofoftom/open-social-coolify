<?php

namespace Drupal\Tests\waap_login\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface as DrupalUserInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Tests for WaaP Login hooks.
 *
 * @coversDefaultClass \Drupal\waap_login
 * @group waap_login
 */
class WaapHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['waap_login', 'user', 'system'];

  /**
   * The session validator service.
   *
   * @var \Drupal\waap_login\Service\WaapSessionValidator
   */
  protected $sessionValidator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installConfig(['waap_login']);
    $this->sessionValidator = $this->container->get('waap_login.session_validator');
  }

  /**
   * Creates a test user.
   *
   * @param array $values
   *   User values.
   *
   * @return \Drupal\user\UserInterface
   *   The created user.
   */
  protected function createUser(array $values = []) {
    $user = User::create($values + [
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Tests hook_user_login() session creation.
   *
   * @covers ::waap_login_user_login
   */
  public function testUserLoginSessionCreation() {
    $user = $this->createUser([
      'name' => 'waap_test_user',
      'mail' => 'test@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'status' => 1,
    ]);

    // Create session data to simulate WaaP login.
    $this->sessionValidator->storeSession($user->id(), [
      'login_type' => 'waap',
      'timestamp' => time(),
      'provider' => 'metamask',
      'address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
    ]);

    // Verify session data was stored.
    $session = $this->sessionValidator->getSession($user->id());

    $this->assertIsArray($session);
    $this->assertArrayHasKey('login_type', $session);
    $this->assertEquals('waap', $session['login_type']);
    $this->assertArrayHasKey('timestamp', $session);
    $this->assertArrayHasKey('provider', $session);
    $this->assertEquals('metamask', $session['provider']);
    $this->assertArrayHasKey('address', $session);
    $this->assertEquals('0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb', $session['address']);

    // Verify user data was stored in keyvalue.
    $userData = \Drupal::keyValue('waap_login.user_data')->get($user->id());
    $this->assertIsArray($userData);
    $this->assertArrayHasKey('last_waap_login', $userData);
    $this->assertArrayHasKey('last_login_type', $userData);
  }

  /**
   * Tests hook_user_login() with different login types.
   *
   * @covers ::waap_login_user_login
   * @dataProvider loginTypeProvider
   */
  public function testUserLoginDifferentTypes($loginType, $expectedProvider) {
    $user = $this->createUser([
      'name' => 'waap_type_test',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
    ]);

    // Create session data with different login type.
    $this->sessionValidator->storeSession($user->id(), [
      'login_type' => $loginType,
      'timestamp' => time(),
      'provider' => $expectedProvider,
      'address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
    ]);

    // Verify session data.
    $session = $this->sessionValidator->getSession($user->id());
    $this->assertEquals($loginType, $session['login_type']);
    $this->assertEquals($expectedProvider, $session['provider']);
  }

  /**
   * Data provider for login types.
   *
   * @return array
   *   Test data.
   */
  public function loginTypeProvider() {
    return [
      ['waap', 'metamask'],
      ['injected', 'injected'],
      ['walletconnect', 'walletconnect'],
    ];
  }

  /**
   * Tests hook_user_logout() session cleanup.
   *
   * @covers ::waap_login_user_logout
   */
  public function testUserLogoutSessionCleanup() {
    $user = $this->createUser([
      'name' => 'waap_logout_test_user',
      'mail' => 'test@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'status' => 1,
    ]);

    // Create session data.
    $this->sessionValidator->storeSession($user->id(), [
      'login_type' => 'waap',
      'timestamp' => time(),
      'provider' => 'metamask',
      'address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
    ]);

    // Create user data.
    \Drupal::keyValue('waap_login.user_data')->set($user->id(), [
      'last_waap_login' => time(),
      'last_login_type' => 'waap',
    ]);

    // Simulate logout by clearing session.
    $this->sessionValidator->clearSession($user->id());

    // Verify session was cleared.
    $session = $this->sessionValidator->getSession($user->id());
    $this->assertNull($session);

    // Verify user data was cleared.
    $userData = \Drupal::keyValue('waap_login.user_data')->get($user->id());
    $this->assertNull($userData);
  }

  /**
   * Tests hook_user_presave() with valid address.
   *
   * @covers ::waap_login_user_presave
   */
  public function testUserPresaveValidAddress() {
    $user = $this->createUser([
      'name' => 'waap_test_user',
      'mail' => 'test@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'status' => 1,
    ]);

    // Try to save with valid address.
    $user->set('field_ethereum_address', '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed');
    $user->save();

    // Verify address was updated.
    $this->assertEquals('0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed', $user->get('field_ethereum_address')->value);
  }

  /**
   * Tests hook_user_presave() with invalid address format.
   *
   * @covers ::waap_login_user_presave
   */
  public function testUserPresaveInvalidAddressFormat() {
    $user = $this->createUser([
      'name' => 'waap_test_user',
      'mail' => 'test@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'status' => 1,
    ]);

    // Try to save with invalid address format.
    $user->set('field_ethereum_address', 'invalid_address');
    $user->save();

    // Verify address was saved (validation happens in form/service, not hook).
    $this->assertEquals('invalid_address', $user->get('field_ethereum_address')->value);
  }

  /**
   * Tests hook_user_presave() with empty address.
   *
   * @covers ::waap_login_user_presave
   */
  public function testUserPresaveEmptyAddress() {
    $user = $this->createUser([
      'name' => 'waap_test_user',
      'mail' => 'test@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'status' => 1,
    ]);

    // Try to save with empty address.
    $user->set('field_ethereum_address', '');
    $user->save();

    // Verify address was cleared.
    $this->assertEquals('', $user->get('field_ethereum_address')->value);
  }

  /**
   * Tests hook_user_presave() with null address.
   *
   * @covers ::waap_login_user_presave
   */
  public function testUserPresaveNullAddress() {
    $user = $this->createUser([
      'name' => 'waap_test_user',
      'mail' => 'test@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'status' => 1,
    ]);

    // Try to save with null address.
    $user->set('field_ethereum_address', NULL);
    $user->save();

    // Verify address was cleared.
    $this->assertNull($user->get('field_ethereum_address')->value);
  }

  /**
   * Tests hook_user_delete() cleanup.
   *
   * @covers ::waap_login_user_delete
   */
  public function testUserDeleteCleanup() {
    $user = $this->createUser([
      'name' => 'waap_delete_test_user',
      'mail' => 'delete@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'status' => 1,
    ]);

    // Create session data.
    $this->sessionValidator->storeSession($user->id(), [
      'login_type' => 'waap',
      'timestamp' => time(),
      'provider' => 'metamask',
      'address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
    ]);

    // Create user data.
    \Drupal::keyValue('waap_login.user_data')->set($user->id(), [
      'last_waap_login' => time(),
      'last_login_type' => 'waap',
    ]);

    // Delete the user.
    $user->delete();

    // Verify session was cleared.
    $session = $this->sessionValidator->getSession($user->id());
    $this->assertNull($session);

    // Verify user data was cleared.
    $userData = \Drupal::keyValue('waap_login.user_data')->get($user->id());
    $this->assertNull($userData);
  }

  /**
   * Tests hook_mail() email template rendering.
   *
   * @covers ::waap_login_mail
   */
  public function testHookMail() {
    // Create a test user.
    $user = $this->createUser([
      'name' => 'waap_mail_test_user',
      'mail' => 'mail@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'status' => 1,
    ]);

    // Build email verification mail.
    $message = [
      'id' => 'waap_login_email_verification',
      'to' => $user->getEmail(),
      'from' => 'noreply@example.com',
      'subject' => '',
      'body' => [],
      'headers' => [],
    ];

    $params = [
      'user' => $user,
      'verification_url' => 'https://example.com/verify',
      'user_name' => 'waap_mail_test_user',
      'wallet_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'site_name' => 'Test Site',
    ];

    // Call hook_mail.
    $this->container->get('module_handler')->invokeAll('mail', ['waap_login', 'email_verification', $message, $params]);

    // Verify subject was set.
    $this->assertNotEmpty($message['subject']);
    $this->assertStringContainsString('Verify', $message['subject']);

    // Verify body was set.
    $this->assertNotEmpty($message['body']);
    $this->assertIsArray($message['body']);
  }

  /**
   * Tests hook_mail() for welcome email.
   *
   * @covers ::waap_login_mail
   */
  public function testHookMailWelcome() {
    // Create a test user.
    $user = $this->createUser([
      'name' => 'waap_welcome_test_user',
      'mail' => 'welcome@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'status' => 1,
    ]);

    // Build welcome mail.
    $message = [
      'id' => 'waap_login_welcome',
      'to' => $user->getEmail(),
      'from' => 'noreply@example.com',
      'subject' => '',
      'body' => [],
      'headers' => [],
    ];

    $params = [
      'user' => $user,
      'user_name' => 'waap_welcome_test_user',
      'wallet_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'site_name' => 'Test Site',
    ];

    // Call hook_mail.
    $this->container->get('module_handler')->invokeAll('mail', ['waap_login', 'welcome', $message, $params]);

    // Verify subject was set.
    $this->assertNotEmpty($message['subject']);
    $this->assertStringContainsString('Welcome', $message['subject']);

    // Verify body was set.
    $this->assertNotEmpty($message['body']);
    $this->assertIsArray($message['body']);
  }

  /**
   * Tests hook_page_attachments() library attachment.
   *
   * @covers ::waap_login_page_attachments
   */
  public function testPageAttachments() {
    $attachments = [
      '#attached' => [
        'library' => [],
        'drupalSettings' => [],
      ],
    ];

    // Call hook_page_attachments.
    $this->container->get('module_handler')->invokeAll('page_attachments', [&$attachments]);

    // Verify library is attached.
    $this->assertContains('waap_login/sdk', $attachments['#attached']['library']);

    // Verify drupalSettings are present.
    $this->assertArrayHasKey('waap_login', $attachments['#attached']['drupalSettings']);
    $this->assertArrayHasKey('enabled', $attachments['#attached']['drupalSettings']['waap_login']);
    $this->assertArrayHasKey('use_staging', $attachments['#attached']['drupalSettings']['waap_login']);
    $this->assertArrayHasKey('authentication_methods', $attachments['#attached']['drupalSettings']['waap_login']);
    $this->assertArrayHasKey('allowed_socials', $attachments['#attached']['drupalSettings']['waap_login']);
    $this->assertArrayHasKey('walletconnect_project_id', $attachments['#attached']['drupalSettings']['waap_login']);
    $this->assertArrayHasKey('enable_dark_mode', $attachments['#attached']['drupalSettings']['waap_login']);
    $this->assertArrayHasKey('show_secured_badge', $attachments['#attached']['drupalSettings']['waap_login']);
    $this->assertArrayHasKey('require_email_verification', $attachments['#attached']['drupalSettings']['waap_login']);
    $this->assertArrayHasKey('require_username', $attachments['#attached']['drupalSettings']['waap_login']);
    $this->assertArrayHasKey('auto_create_users', $attachments['#attached']['drupalSettings']['waap_login']);
    $this->assertArrayHasKey('session_ttl', $attachments['#attached']['drupalSettings']['waap_login']);
    $this->assertArrayHasKey('referral_code', $attachments['#attached']['drupalSettings']['waap_login']);
    $this->assertArrayHasKey('gas_tank_enabled', $attachments['#attached']['drupalSettings']['waap_login']);
    $this->assertArrayHasKey('siwe_integration', $attachments['#attached']['drupalSettings']['waap_login']);
  }

  /**
   * Tests hook_cron() cleanup tasks.
   *
   * @covers ::waap_login_cron
   */
  public function testHookCron() {
    // Create a user with expired session data.
    $user = $this->createUser([
      'name' => 'waap_cron_test_user',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
    ]);

    $this->sessionValidator->storeSession($user->id(), [
      'login_type' => 'waap',
      'timestamp' => time() - 90000, // 25 hours ago
      'expires' => time(),
      'provider' => 'metamask',
      'address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
    ]);

    // Create expired temp store data.
    $tempStore = \Drupal::service('user.shared_tempstore')->get('waap_login');
    $tempStore->set('pending_verification_' . $user->id(), [
      'created' => time() - 90000,
      'email' => 'cron_test@example.com',
      'uid' => $user->id(),
    ]);

    // Run cron.
    $this->container->get('cron')->run();

    // Verify expired session was cleaned up.
    $session = $this->sessionValidator->getSession($user->id());
    $this->assertNull($session, 'Expired session should be cleaned up by cron');

    // Verify temp store was cleaned.
    $tempStoreData = $tempStore->get('pending_verification_' . $user->id());
    $this->assertNull($tempStoreData, 'Expired temp store data should be cleaned up by cron');
  }

  /**
   * Tests hook_requirements() at install.
   *
   * @covers ::waap_login_requirements
   */
  public function testHookRequirementsInstall() {
    // Run requirements hook for install phase.
    $requirements = $this->container->get('module_handler')->invoke('waap_login', 'requirements', ['install']);

    // Should return array of requirements.
    $this->assertIsArray($requirements);

    // Verify waap_login requirement exists.
    $this->assertArrayHasKey('waap_login', $requirements);
    $this->assertIsArray($requirements['waap_login']);
    $this->assertArrayHasKey('title', $requirements['waap_login']);
    $this->assertArrayHasKey('description', $requirements['waap_login']);
    $this->assertArrayHasKey('severity', $requirements['waap_login']);
  }

  /**
   * Tests hook_requirements() at runtime.
   *
   * @covers ::waap_login_requirements
   */
  public function testHookRequirementsRuntime() {
    // Run requirements hook for runtime phase.
    $requirements = $this->container->get('module_handler')->invoke('waap_login', 'requirements', ['runtime']);

    // Should return array of requirements.
    $this->assertIsArray($requirements);

    // Verify waap_login requirement exists.
    $this->assertArrayHasKey('waap_login', $requirements);
    $this->assertIsArray($requirements['waap_login']);
    $this->assertArrayHasKey('title', $requirements['waap_login']);
    $this->assertArrayHasKey('description', $requirements['waap_login']);
    $this->assertArrayHasKey('severity', $requirements['waap_login']);
  }

  /**
   * Tests hook_entity_insert() for new users.
   *
   * @covers ::waap_login_entity_insert
   */
  public function testHookEntityInsert() {
    $user = $this->createUser([
      'name' => 'waap_new_user',
      'mail' => 'new@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
    ]);

    // Verify user was created.
    $this->assertNotNull($user->id());

    // Verify address is stored.
    $this->assertEquals('0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb', $user->get('field_ethereum_address')->value);
  }

  /**
   * Tests hook_entity_update() for user updates.
   *
   * @covers ::waap_login_entity_update
   */
  public function testHookEntityUpdate() {
    $user = $this->createUser([
      'name' => 'waap_update_user',
      'mail' => 'update@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
    ]);

    // Update user.
    $user->set('mail', 'updated@example.com');
    $user->save();

    // Verify user was updated.
    $this->assertEquals('updated@example.com', $user->getEmail());

    // Verify address is still present.
    $this->assertEquals('0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb', $user->get('field_ethereum_address')->value);
  }

  /**
   * Tests hook_form_alter() for user login form.
   *
   * @covers ::waap_login_form_alter
   */
  public function testHookFormAlterUserLogin() {
    $form = [];
    $form_state = $this->createMock('\Drupal\Core\Form\FormStateInterface');
    $form_id = 'user_login_form';

    // Call hook_form_alter.
    $this->container->get('module_handler')->alter('form', $form, $form_state, $form_id);

    // Verify WaaP login button is added.
    $this->assertArrayHasKey('waap_login', $form);
  }

  /**
   * Tests hook_form_alter() for user register form.
   *
   * @covers ::waap_login_form_alter
   */
  public function testHookFormAlterUserRegister() {
    $form = [];
    $form_state = $this->createMock('\Drupal\Core\Form\FormStateInterface');
    $form_id = 'user_register_form';

    // Call hook_form_alter.
    $this->container->get('module_handler')->alter('form', $form, $form_state, $form_id);

    // Verify WaaP login button is added.
    $this->assertArrayHasKey('waap_login', $form);
  }

  /**
   * Tests hook_theme() for templates.
   *
   * @covers ::waap_login_theme
   */
  public function testHookTheme() {
    $themes = $this->container->get('module_handler')->invoke('waap_login', 'theme');

    // Verify themes are registered.
    $this->assertIsArray($themes);
    $this->assertArrayHasKey('waap_login_button', $themes);
    $this->assertArrayHasKey('waap_logout_button', $themes);
    $this->assertArrayHasKey('waap_email_verification_html', $themes);
    $this->assertArrayHasKey('waap_email_verification_text', $themes);
    $this->assertArrayHasKey('waap_welcome_html', $themes);
    $this->assertArrayHasKey('waap_welcome_text', $themes);

    // Verify theme definitions.
    $this->assertArrayHasKey('variables', $themes['waap_login_button']);
    $this->assertArrayHasKey('template', $themes['waap_login_button']);
  }

}
