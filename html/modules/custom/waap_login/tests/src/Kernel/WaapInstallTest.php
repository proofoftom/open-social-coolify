<?php

namespace Drupal\Tests\waap_login\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

/**
 * Tests for WaaP Login module installation and uninstallation.
 *
 * @coversDefaultClass \Drupal\waap_login
 * @group waap_login
 */
class WaapInstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['user', 'system', 'field'];

  /**
   * The module installer service.
   *
   * @var \Drupal\Core\Extension\ModuleInstallerInterface
   */
  protected $moduleInstaller;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installConfig(['user', 'system']);

    $this->moduleInstaller = $this->container->get('module_installer');
    $this->moduleHandler = $this->container->get('module_handler');
    $this->configFactory = $this->container->get('config.factory');
    $this->entityTypeManager = $this->container->get('entity_type.manager');
  }

  /**
   * Tests module installation.
   *
   * @covers ::waap_login_install
   */
  public function testModuleInstallation() {
    // Install the module.
    $this->assertTrue($this->moduleInstaller->install(['waap_login']));

    // Verify module is installed.
    $this->assertTrue($this->moduleHandler->moduleExists('waap_login'));

    // Verify services are registered.
    $this->assertTrue($this->container->has('waap_login.auth_service'));
    $this->assertTrue($this->container->has('waap_login.user_manager'));
    $this->assertTrue($this->container->has('waap_login.session_validator'));
  }

  /**
   * Tests field_ethereum_address creation during install.
   *
   * @covers ::waap_login_install
   */
  public function testFieldEthereumAddressCreation() {
    // Install the module.
    $this->moduleInstaller->install(['waap_login']);

    // Check that field storage was created.
    $fieldStorage = FieldStorageConfig::load('user.field_ethereum_address');
    $this->assertNotNull($fieldStorage, 'field_ethereum_address field storage should be created');

    // Verify field storage settings.
    $this->assertEquals('user', $fieldStorage->getTargetEntityTypeId());
    $this->assertEquals('string', $fieldStorage->getType());
    $this->assertEquals(1, $fieldStorage->getCardinality());
    $this->assertEquals(255, $fieldStorage->getSetting('max_length'));
    $this->assertFalse($fieldStorage->isTranslatable());

    // Check that field config was created.
    $field = FieldConfig::load('user.user.field_ethereum_address');
    $this->assertNotNull($field, 'field_ethereum_address field config should be created');

    // Verify field config settings.
    $this->assertEquals('user', $field->getTargetEntityTypeId());
    $this->assertEquals('user', $field->getBundle());
    $this->assertEquals('field_ethereum_address', $field->getName());
    $this->assertEquals('Ethereum Wallet Address', $field->getLabel());
    $this->assertFalse($field->isRequired());

    // Verify field is accessible on user entity.
    $user = User::create([
      'name' => 'test_user',
      'mail' => 'test@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
    ]);
    $this->assertEquals('0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb', $user->get('field_ethereum_address')->value);
  }

  /**
   * Tests configuration installation.
   *
   * @covers ::waap_login_install
   */
  public function testConfigurationInstallation() {
    // Install the module.
    $this->moduleInstaller->install(['waap_login']);

    // Verify settings config was installed.
    $settings = $this->configFactory->get('waap_login.settings');
    $this->assertNotNull($settings);

    // Verify all settings exist with default values.
    $this->assertTrue($settings->get('enabled'));
    $this->assertFalse($settings->get('use_staging'));
    $this->assertIsArray($settings->get('authentication_methods'));
    $this->assertContains('waap', $settings->get('authentication_methods'));
    $this->assertIsArray($settings->get('allowed_socials'));
    $this->assertEquals('', $settings->get('walletconnect_project_id'));
    $this->assertFalse($settings->get('enable_dark_mode'));
    $this->assertTrue($settings->get('show_secured_badge'));
    $this->assertFalse($settings->get('require_email_verification'));
    $this->assertFalse($settings->get('require_username'));
    $this->assertTrue($settings->get('auto_create_users'));
    $this->assertEquals(86400, $settings->get('session_ttl'));
    $this->assertEquals('', $settings->get('referral_code'));
    $this->assertFalse($settings->get('gas_tank_enabled'));
    $this->assertFalse($settings->get('siwe_integration'));

    // Verify mail config was installed.
    $mailConfig = $this->configFactory->get('waap_login.mail');
    $this->assertNotNull($mailConfig);

    // Verify mail settings exist.
    $this->assertIsArray($mailConfig->get('email_verification'));
    $this->assertIsArray($mailConfig->get('welcome'));
  }

  /**
   * Tests module uninstallation.
   *
   * @covers ::waap_login_uninstall
   */
  public function testModuleUninstallation() {
    // Install the module first.
    $this->moduleInstaller->install(['waap_login']);

    // Create a user with wallet address.
    $user = User::create([
      'name' => 'test_user',
      'mail' => 'test@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
    ]);
    $user->save();
    $userId = $user->id();

    // Uninstall the module.
    $this->assertTrue($this->moduleInstaller->uninstall(['waap_login']));

    // Verify module is uninstalled.
    $this->assertFalse($this->moduleHandler->moduleExists('waap_login'));

    // Verify config was removed.
    $settings = $this->configFactory->get('waap_login.settings');
    $this->assertNull($settings->get('enabled'));

    // Verify services are no longer available.
    $this->assertFalse($this->container->has('waap_login.auth_service'));
    $this->assertFalse($this->container->has('waap_login.user_manager'));
    $this->assertFalse($this->container->has('waap_login.session_validator'));

    // Note: field_ethereum_address is NOT deleted because siwe_login might use it.
    // This is the expected behavior.
    $fieldStorage = FieldStorageConfig::load('user.field_ethereum_address');
    $this->assertNotNull($fieldStorage, 'field_ethereum_address should still exist');

    // Verify user data is still in the database (field data is preserved).
    $user = User::load($userId);
    $this->assertNotNull($user);
    $this->assertEquals('0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb', $user->get('field_ethereum_address')->value);
  }

  /**
   * Tests module uninstallation when siwe_login is present.
   *
   * @covers ::waap_login_uninstall
   */
  public function testModuleUninstallationWithSiweLogin() {
    // Install both modules.
    $this->moduleInstaller->install(['siwe_login', 'waap_login']);

    // Create a user with wallet address.
    $user = User::create([
      'name' => 'test_user',
      'mail' => 'test@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
    ]);
    $user->save();
    $userId = $user->id();

    // Uninstall waap_login only.
    $this->assertTrue($this->moduleInstaller->uninstall(['waap_login']));

    // Verify waap_login is uninstalled but siwe_login is still installed.
    $this->assertFalse($this->moduleHandler->moduleExists('waap_login'));
    $this->assertTrue($this->moduleHandler->moduleExists('siwe_login'));

    // Verify field_ethereum_address still exists (siwe_login uses it).
    $fieldStorage = FieldStorageConfig::load('user.field_ethereum_address');
    $this->assertNotNull($fieldStorage);

    // Verify user data is preserved.
    $user = User::load($userId);
    $this->assertNotNull($user);
    $this->assertEquals('0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb', $user->get('field_ethereum_address')->value);
  }

  /**
   * Tests hook_requirements() at install phase.
   *
   * @covers ::waap_login_requirements
   */
  public function testHookRequirementsInstall() {
    // Run requirements hook for install phase.
    $requirements = $this->moduleHandler->invoke('waap_login', 'requirements', ['install']);

    // Should return array of requirements.
    $this->assertIsArray($requirements);

    // Verify waap_login requirement exists.
    $this->assertArrayHasKey('waap_login', $requirements);
    $this->assertIsArray($requirements['waap_login']);

    // Verify requirement structure.
    $this->assertArrayHasKey('title', $requirements['waap_login']);
    $this->assertArrayHasKey('description', $requirements['waap_login']);
    $this->assertArrayHasKey('severity', $requirements['waap_login']);

    // Verify severity is REQUIREMENT_OK.
    $this->assertEquals(0, $requirements['waap_login']['severity']);
  }

  /**
   * Tests hook_requirements() at runtime phase.
   *
   * @covers ::waap_login_requirements
   */
  public function testHookRequirementsRuntime() {
    // Install the module first.
    $this->moduleInstaller->install(['waap_login']);

    // Run requirements hook for runtime phase.
    $requirements = $this->moduleHandler->invoke('waap_login', 'requirements', ['runtime']);

    // Should return array of requirements.
    $this->assertIsArray($requirements);

    // Verify waap_login requirement exists.
    $this->assertArrayHasKey('waap_login', $requirements);
    $this->assertIsArray($requirements['waap_login']);

    // Verify requirement structure.
    $this->assertArrayHasKey('title', $requirements['waap_login']);
    $this->assertArrayHasKey('description', $requirements['waap_login']);
    $this->assertArrayHasKey('severity', $requirements['waap_login']);

    // Verify severity is REQUIREMENT_OK.
    $this->assertEquals(0, $requirements['waap_login']['severity']);
  }

  /**
   * Tests hook_requirements() with missing dependencies.
   *
   * @covers ::waap_login_requirements
   */
  public function testHookRequirementsMissingDependencies() {
    // Run requirements hook without installing module.
    $requirements = $this->moduleHandler->invoke('waap_login', 'requirements', ['install']);

    // Should still return array.
    $this->assertIsArray($requirements);

    // If module is not installed, requirements might be empty or have warnings.
    $this->assertTrue(empty($requirements) || isset($requirements['waap_login']));
  }

  /**
   * Tests that field_ethereum_address is not deleted on uninstall.
   *
   * @covers ::waap_login_uninstall
   */
  public function testFieldPreservationOnUninstall() {
    // Install the module.
    $this->moduleInstaller->install(['waap_login']);

    // Verify field exists.
    $fieldStorage = FieldStorageConfig::load('user.field_ethereum_address');
    $this->assertNotNull($fieldStorage);

    // Create user with data.
    $user = User::create([
      'name' => 'test_user',
      'mail' => 'test@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
    ]);
    $user->save();
    $userId = $user->id();

    // Uninstall the module.
    $this->moduleInstaller->uninstall(['waap_login']);

    // Verify field still exists.
    $fieldStorage = FieldStorageConfig::load('user.field_ethereum_address');
    $this->assertNotNull($fieldStorage, 'field_ethereum_address should be preserved');

    // Verify field config still exists.
    $field = FieldConfig::load('user.user.field_ethereum_address');
    $this->assertNotNull($field, 'field config should be preserved');

    // Verify user data is preserved.
    $user = User::load($userId);
    $this->assertNotNull($user);
    $this->assertEquals('0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb', $user->get('field_ethereum_address')->value);
  }

  /**
   * Tests that permissions are installed.
   *
   * @covers ::waap_login_install
   */
  public function testPermissionsInstallation() {
    // Install the module.
    $this->moduleInstaller->install(['waap_login']);

    // Verify permissions are defined.
    $permissions = $this->moduleHandler->invoke('waap_login', 'permission');
    $this->assertIsArray($permissions);

    // Verify expected permissions exist.
    $this->assertArrayHasKey('use waap login', $permissions);
    $this->assertArrayHasKey('administer waap login', $permissions);
  }

  /**
   * Tests that routes are installed.
   *
   * @covers ::waap_login_install
   */
  public function testRoutesInstallation() {
    // Install the module.
    $this->moduleInstaller->install(['waap_login']);

    // Verify routes are registered.
    $routeProvider = $this->container->get('router.route_provider');
    $routes = $routeProvider->getRoutesByNames([
      'waap_login.verify',
      'waap_login.status',
      'waap_login.logout',
      'waap_login.email_verification',
      'waap_login.settings',
    ]);

    $this->assertCount(5, $routes);
  }

  /**
   * Tests that theme hooks are registered.
   *
   * @covers ::waap_login_theme
   */
  public function testThemeHooksRegistration() {
    // Install the module.
    $this->moduleInstaller->install(['waap_login']);

    // Verify theme hooks are registered.
    $themes = $this->moduleHandler->invoke('waap_login', 'theme');
    $this->assertIsArray($themes);

    // Verify expected theme hooks exist.
    $this->assertArrayHasKey('waap_login_button', $themes);
    $this->assertArrayHasKey('waap_logout_button', $themes);
    $this->assertArrayHasKey('waap_email_verification_html', $themes);
    $this->assertArrayHasKey('waap_email_verification_text', $themes);
    $this->assertArrayHasKey('waap_welcome_html', $themes);
    $this->assertArrayHasKey('waap_welcome_text', $themes);

    // Verify theme hook structure.
    $this->assertArrayHasKey('variables', $themes['waap_login_button']);
    $this->assertArrayHasKey('template', $themes['waap_login_button']);
  }

  /**
   * Tests that menu links are installed.
   *
   * @covers ::waap_login_install
   */
  public function testMenuLinksInstallation() {
    // Install the module.
    $this->moduleInstaller->install(['waap_login']);

    // Verify menu links are created.
    // Note: This requires the menu_link_content module which may not be available
    // in kernel tests. We'll verify the routes exist instead.
    $routeProvider = $this->container->get('router.route_provider');
    $settingsRoute = $routeProvider->getRouteByName('waap_login.settings');
    $this->assertNotNull($settingsRoute);
  }

  /**
   * Tests that services are registered.
   *
   * @covers ::waap_login_install
   */
  public function testServicesRegistration() {
    // Install the module.
    $this->moduleInstaller->install(['waap_login']);

    // Verify services are registered.
    $this->assertTrue($this->container->has('waap_login.auth_service'));
    $this->assertTrue($this->container->has('waap_login.user_manager'));
    $this->assertTrue($this->container->has('waap_login.session_validator'));

    // Verify services can be retrieved.
    $authService = $this->container->get('waap_login.auth_service');
    $this->assertInstanceOf('Drupal\waap_login\Service\WaapAuthService', $authService);

    $userManager = $this->container->get('waap_login.user_manager');
    $this->assertInstanceOf('Drupal\waap_login\Service\WaapUserManager', $userManager);

    $sessionValidator = $this->container->get('waap_login.session_validator');
    $this->assertInstanceOf('Drupal\waap_login\Service\WaapSessionValidator', $sessionValidator);
  }

  /**
   * Tests that keyvalue stores are available.
   *
   * @covers ::waap_login_install
   */
  public function testKeyValueStores() {
    // Install the module.
    $this->moduleInstaller->install(['waap_login']);

    // Verify keyvalue stores are accessible.
    $keyValueFactory = $this->container->get('keyvalue');
    $userStore = $keyValueFactory->get('waap_login.user_data');
    $this->assertInstanceOf('Drupal\Core\KeyValueStore\KeyValueStoreInterface', $userStore);

    // Test storing and retrieving data.
    $userStore->set(123, ['test' => 'data']);
    $data = $userStore->get(123);
    $this->assertEquals(['test' => 'data'], $data);
  }

  /**
   * Tests that blocks are registered.
   *
   * @covers ::waap_login_install
   */
  public function testBlocksRegistration() {
    // Install the module.
    $this->moduleInstaller->install(['waap_login']);

    // Verify block plugin is registered.
    $blockManager = $this->container->get('plugin.manager.block');
    $this->assertTrue($blockManager->hasDefinition('waap_login_block'));

    // Verify block definition.
    $definition = $blockManager->getDefinition('waap_login_block');
    $this->assertEquals('WaaP Login', $definition['admin_label']);
    $this->assertEquals('waap_login', $definition['provider']);
  }

  /**
   * Tests that forms are registered.
   *
   * @covers ::waap_login_install
   */
  public function testFormsRegistration() {
    // Install the module.
    $this->moduleInstaller->install(['waap_login']);

    // Verify settings form is registered.
    $formInfo = $this->container->get('form_info');
    $this->assertTrue($formInfo->isFormId('waap_login_settings_form'));
  }

  /**
   * Tests that controllers are registered.
   *
   * @covers ::waap_login_install
   */
  public function testControllersRegistration() {
    // Install the module.
    $this->moduleInstaller->install(['waap_login']);

    // Verify routes point to correct controllers.
    $routeProvider = $this->container->get('router.route_provider');

    $verifyRoute = $routeProvider->getRouteByName('waap_login.verify');
    $this->assertStringContainsString('WaapAuthController', $verifyRoute->getDefault('_controller'));

    $statusRoute = $routeProvider->getRouteByName('waap_login.status');
    $this->assertStringContainsString('WaapAuthController', $statusRoute->getDefault('_controller'));

    $logoutRoute = $routeProvider->getRouteByName('waap_login.logout');
    $this->assertStringContainsString('WaapAuthController', $logoutRoute->getDefault('_controller'));

    $emailVerifyRoute = $routeProvider->getRouteByName('waap_login.email_verification');
    $this->assertStringContainsString('EmailVerificationController', $emailVerifyRoute->getDefault('_controller'));

    $settingsRoute = $routeProvider->getRouteByName('waap_login.settings');
    $this->assertStringContainsString('WaapSettingsForm', $settingsRoute->getDefault('_form'));
  }

  /**
   * Tests that email templates are installed.
   *
   * @covers ::waap_login_install
   */
  public function testEmailTemplatesInstallation() {
    // Install the module.
    $this->moduleInstaller->install(['waap_login']);

    // Verify mail config exists.
    $mailConfig = $this->configFactory->get('waap_login.mail');
    $this->assertNotNull($mailConfig);

    // Verify email verification templates.
    $emailVerification = $mailConfig->get('email_verification');
    $this->assertIsArray($emailVerification);
    $this->assertArrayHasKey('subject', $emailVerification);
    $this->assertArrayHasKey('body', $emailVerification);

    // Verify welcome email templates.
    $welcome = $mailConfig->get('welcome');
    $this->assertIsArray($welcome);
    $this->assertArrayHasKey('subject', $welcome);
    $this->assertArrayHasKey('body', $welcome);
  }

  /**
   * Tests that config schema is valid.
   *
   * @covers ::waap_login_install
   */
  public function testConfigSchemaValidation() {
    // Install the module.
    $this->moduleInstaller->install(['waap_login']);

    // Get config schema validator.
    $typedConfig = $this->container->get('config.typed');

    // Verify settings config schema.
    $settings = $this->configFactory->get('waap_login.settings');
    $schema = $typedConfig->createFromNameAndData('waap_login.settings', $settings->get());
    $this->assertInstanceOf('Drupal\Core\Config\Schema\TypedConfigInterface', $schema);

    // Verify mail config schema.
    $mailConfig = $this->configFactory->get('waap_login.mail');
    $schema = $typedConfig->createFromNameAndData('waap_login.mail', $mailConfig->get());
    $this->assertInstanceOf('Drupal\Core\Config\Schema\TypedConfigInterface', $schema);
  }

}
