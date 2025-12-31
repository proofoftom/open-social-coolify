<?php

namespace Drupal\Tests\waap_login\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Tests for WaapSettingsForm.
 *
 * @coversDefaultClass \Drupal\waap_login\Form\WaapSettingsForm
 * @group waap_login
 */
class WaapSettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['waap_login', 'user', 'system'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateUser([
      'name' => 'admin_user',
      'mail' => 'admin@example.com',
      'status' => 1,
    ]);
    $this->drupalLogin('admin_user');
  }

  /**
   * Tests admin form access with permission.
   *
   * @covers \Drupal\waap_login\Form\WaapSettingsForm
   */
  public function testAdminFormAccessWithPermission() {
    $this->drupalGet('/admin/config/people/waap');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('WaaP Login Settings');
  }

  /**
   * Tests admin form access without permission.
   *
   * @covers \Drupal\waap_login\Form\WaapSettingsForm
   */
  public function testAdminFormAccessWithoutPermission() {
    // Logout admin user.
    $this->drupalLogout();

    // Create regular user without permission.
    $regular_user = $this->drupalCreateUser([
      'name' => 'regular_user',
      'mail' => 'regular@example.com',
      'status' => 1,
    ]);
    $this->drupalLogin('regular_user');

    // Try to access admin form.
    $this->drupalGet('/admin/config/people/waap');

    // Should be denied.
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests form submission with valid configuration.
   *
   * @covers \Drupal\waap_login\Form\WaapSettingsForm
   */
  public function testFormSubmissionValid() {
    $edit = [
      'enabled' => TRUE,
      'use_staging' => FALSE,
      'authentication_methods' => ['email', 'social', 'wallet'],
      'allowed_socials' => ['google', 'twitter', 'discord'],
      'walletconnect_project_id' => 'test_project_id',
      'enable_dark_mode' => TRUE,
      'show_secured_badge' => TRUE,
      'require_email_verification' => TRUE,
      'require_username' => TRUE,
      'auto_create_users' => TRUE,
      'session_ttl' => 86400,
      'referral_code' => 'test_referral',
      'gas_tank_enabled' => FALSE,
      'siwe_integration' => TRUE,
    ];

    $this->drupalPostForm(NULL, $edit, 'Save configuration');

    $this->assertSession()->pageTextContains('The configuration options have been saved');

    // Verify configuration was saved.
    $this->assertConfig('waap_login.settings', 'enabled', TRUE);
    $this->assertConfig('waap_login.settings', 'use_staging', FALSE);
  }

  /**
   * Tests form submission with empty checkboxes.
   *
   * @covers \Drupal\waap_login\Form\WaapSettingsForm
   */
  public function testFormSubmissionEmptyCheckboxes() {
    $edit = [
      'enabled' => TRUE,
      'authentication_methods' => [],
      'allowed_socials' => [],
      'require_email_verification' => FALSE,
      'require_username' => FALSE,
      'auto_create_users' => FALSE,
    'session_ttl' => 86400,
    ];

    $this->drupalPostForm(NULL, $edit, 'Save configuration');

    $this->assertSession()->pageTextContains('The configuration options have been saved');

    // Verify empty arrays were saved.
    $this->assertConfig('waap_login.settings', 'authentication_methods', []);
    $this->assertConfig('waap_login.settings', 'allowed_socials', []);
  }

  /**
   * Tests form submission with invalid session TTL.
   *
   * @covers \Drupal\waap_login\Form\WaapSettingsForm
   */
  public function testFormSubmissionInvalidSessionTtl() {
    $edit = [
      'session_ttl' => 30, // Below minimum of 60.
    ];

    $this->drupalPostForm(NULL, $edit, 'Save configuration');

    // Should show validation error.
    $this->assertSession()->pageTextContains('Session TTL must be at least 60 seconds');
  }

  /**
   * Tests form submission with invalid WalletConnect project ID.
   *
   * @covers \Drupal\waap_login\Form\WaapSettingsForm
   */
  public function testFormSubmissionInvalidWalletConnectId() {
    $edit = [
      'walletconnect_project_id' => 'invalid_id_with_spaces',
    ];

    $this->drupalPostForm(NULL, $edit, 'Save configuration');

    // Should show validation error.
    $this->assertSession()->pageTextContains('WalletConnect Project ID must contain only alphanumeric characters');
  }

  /**
   * Tests form submission with environment selection.
   *
   * @covers \Drupal\waap_login\Form\WaapSettingsForm
   */
  public function testFormSubmissionEnvironmentSelection() {
    // Test staging environment.
    $edit_staging = [
      'use_staging' => TRUE,
      'enabled' => TRUE,
    ];

    $this->drupalPostForm(NULL, $edit_staging, 'Save configuration');

    $this->assertConfig('waap_login.settings', 'use_staging', TRUE);

    // Test production environment.
    $edit_production = [
      'use_staging' => FALSE,
      'enabled' => TRUE,
    ];

    $this->drupalPostForm(NULL, $edit_production, 'Save configuration');

    $this->assertConfig('waap_login.settings', 'use_staging', FALSE);
  }

  /**
   * Tests form submission with social provider visibility.
   *
   * @covers \Drupal\waap_login\Form\WaapSettingsForm
   */
  public function testFormSubmissionSocialProviderVisibility() {
    $edit = [
      'authentication_methods' => ['email', 'social', 'wallet'],
      'allowed_socials' => ['google', 'twitter', 'discord'],
    ];

    $this->drupalPostForm(NULL, $edit, 'Save configuration');

    // Social providers should be visible.
    $this->assertSession()->fieldExists('css', '[name="edit[allowed_socials]"]');

    // Now disable social, social providers should not be visible.
    $edit_no_social = [
      'authentication_methods' => ['email', 'wallet'],
      'allowed_socials' => ['google', 'twitter', 'discord'],
    ];

    $this->drupalPostForm(NULL, $edit_no_social, 'Save configuration');

    $this->assertSession()->fieldNotExists('css', 'name="edit[allowed_socials]"]');
  }

  /**
   * Tests form field validation for required fields.
   *
   * @covers \Drupal\waap_login\Form\WaapSettingsForm
   */
  public function testFormValidation() {
    // Test with missing enabled field.
    $edit = [
      'use_staging' => TRUE,
    ];

    $this->drupalPostForm(NULL, $edit, 'Save configuration');

    $this->assertSession()->fieldExists('css', 'name="edit[enabled]"]');
  }

  /**
   * Tests configuration retrieval.
   *
   * @covers \Drupal\waap_login\Form\WaapSettingsForm
   */
  public function testConfigurationRetrieval() {
    // Set some configuration values.
    $this->config('waap_login.settings')
      ->set('enabled', TRUE)
      ->set('use_staging', TRUE)
      ->set('require_email_verification', FALSE)
      ->set('require_username', FALSE)
      ->set('auto_create_users', TRUE)
      ->set('session_ttl', 86400)
      ->save();

    // Visit the form page.
    $this->drupalGet('/admin/config/people/waap');

    // Verify form values match configuration.
    $this->assertFieldByName('edit[enabled]', 'checked');
    $this->assertFieldByName('edit[use_staging]', 'checked');
    $this->assertFieldByName('edit[require_email_verification]', 'checked');
    $this->assertFieldByName('edit[require_username]', 'checked');
    $this->assertFieldByName('edit[auto_create_users]', 'checked');
    $this->assertFieldByName('edit[session_ttl]', '86400');
  }

  /**
   * Tests form CSRF protection.
   *
   * @covers \Drupal\waap_login\Form\WaapSettingsForm
   */
  public function testFormCsrfProtection() {
    $this->drupalLogout();

    // Try to submit form without CSRF token.
    $this->drupalPostForm(NULL, [], 'Save configuration');

    // Should be denied.
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextContains('Access denied');
  }

  /**
   * Tests form with SIWE integration field.
   *
   * @covers \Drupal\waap_login\Form\WaapSettingsForm
   */
  public function testSiweIntegrationField() {
    $this->drupalGet('/admin/config/people/waap');

    // SIWE integration field should be present.
    $this->assertSession()->fieldExists('name', 'edit[siwe_integration]');
  }

  /**
   * Tests form submission with Gas Tank enabled.
   *
   * @covers \Drupal\waap_login\Form\WaapSettingsForm
   */
  public function testGasTankEnabled() {
    $edit = [
      'gas_tank_enabled' => TRUE,
      'enabled' => TRUE,
    ];

    $this->drupalPostForm(NULL, $edit, 'Save configuration');

    $this->assertConfig('waap_login.settings', 'gas_tank_enabled', TRUE);
    $this->assertSession()->pageTextContains('The configuration options have been saved');
  }

  /**
   * Tests form submission with referral code.
   *
   * @covers \Drupal\waap_login\Form\WaapSettingsForm
   */
  public function testReferralCode() {
    $edit = [
      'referral_code' => 'my_referral_code_123',
      'enabled' => TRUE,
    ];

    $this->drupalPostForm(NULL, $edit, 'Save configuration');

    $this->assertConfig('waap_login.settings', 'referral_code', 'my_referral_code_123');
  }

  /**
   * Tests form submission with all settings.
   *
   * @covers \Drupal\waap_login\Form\WaapSettingsForm
   */
  public function testCompleteFormSubmission() {
    $edit = [
      'enabled' => TRUE,
      'use_staging' => FALSE,
      'authentication_methods' => ['email', 'social', 'wallet'],
      'allowed_socials' => ['google', 'twitter', 'discord', 'coinbase'],
      'walletconnect_project_id' => 'my_project_id',
      'enable_dark_mode' => TRUE,
      'show_secured_badge' => TRUE,
      'require_email_verification' => FALSE,
      'require_username' => FALSE,
      'auto_create_users' => TRUE,
      'session_ttl' => 86400,
      'referral_code' => 'test_referral',
      'gas_tank_enabled' => TRUE,
      'siwe_integration' => TRUE,
    ];

    $this->drupalPostForm(NULL, $edit, 'Save configuration');

    // Verify all settings were saved.
    $this->assertConfig('waap_login.settings', 'enabled', TRUE);
    $this->assertConfig('waap_login.settings', 'use_staging', FALSE);
    $this->assertConfig('waap_login.settings', 'authentication_methods', ['email', 'social', 'wallet']);
    $this->assertConfig('waap_login.settings', 'allowed_socials', ['google', 'twitter', 'discord', 'coinbase']);
    $this->assertConfig('waap_login.settings', 'walletconnect_project_id', 'my_project_id');
    $this->assertConfig('waap_login.settings', 'enable_dark_mode', TRUE);
    $this->assertConfig('waap_login.settings', 'show_secured_badge', TRUE);
    $this->assertConfig('waap_login.settings', 'require_email_verification', FALSE);
    $this->assertConfig('waap_login.settings', 'require_username', FALSE);
    $this->assertConfig('waap_login.settings', 'auto_create_users', TRUE);
    $this->assertConfig('waap_login.settings', 'session_ttl', 86400);
    $this->assertConfig('waap_login.settings', 'referral_code', 'test_referral');
    $this->assertConfig('waap_login.settings', 'gas_tank_enabled', TRUE);
    $this->assertConfig('waap_login.settings', 'siwe_integration', TRUE);
  }

  /**
   * Tests form submission with default values.
   *
   * @covers \Drupal\waap_login\Form\WaapSettingsForm
   */
  public function testFormResetToDefaults() {
    // Set all values.
    $edit = [
      'enabled' => TRUE,
      'use_staging' => TRUE,
      'authentication_methods' => ['email', 'social', 'wallet'],
      'allowed_socials' => ['google', 'twitter', 'discord'],
      'walletconnect_project_id' => 'test_project_id',
      'enable_dark_mode' => TRUE,
      'show_secured_badge' => TRUE,
      'require_email_verification' => TRUE,
      'require_username' => TRUE,
      'auto_create_users' => TRUE,
      'session_ttl' => 86400,
      'referral_code' => 'test_referral',
      'gas_tank_enabled' => TRUE,
      'siwe_integration' => TRUE,
    ];

    $this->drupalPostForm(NULL, $edit, 'Save configuration');

    // Reset to defaults.
    $edit_reset = [
      'enabled' => FALSE,
    ];

    $this->drupalPostForm(NULL, $edit_reset, 'Reset to defaults');

    // Verify reset.
    $this->assertConfig('waap_login.settings', 'enabled', FALSE);
  }

  /**
   * Helper method to assert config value.
   *
   * @param string $key
   *   The config key.
   * @param mixed $expected
   *   The expected value.
   */
  protected function assertConfig($key, $expected) {
    $this->assertEquals($expected, $this->config('waap_login.settings')->get($key));
  }

}
