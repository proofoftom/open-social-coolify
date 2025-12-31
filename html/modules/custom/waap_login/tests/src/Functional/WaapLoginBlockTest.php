<?php

namespace Drupal\Tests\waap_login\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;

/**
 * Tests for WaapLoginBlock.
 *
 * @coversDefaultClass \Drupal\waap_login\Plugin\Block\WaapLoginBlock
 * @group waap_login
 */
class WaapLoginBlockTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['waap_login', 'block', 'user', 'system'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Enable WaaP module.
    $this->config('waap_login.settings')
      ->set('enabled', TRUE)
      ->set('use_staging', FALSE)
      ->set('authentication_methods', ['email', 'social', 'wallet'])
      ->set('allowed_socials', ['google', 'twitter', 'discord'])
      ->set('walletconnect_project_id', 'test_project_id')
      ->set('enable_dark_mode', TRUE)
      ->set('show_secured_badge', TRUE)
      ->set('require_email_verification', FALSE)
      ->set('require_username', FALSE)
      ->set('auto_create_users', TRUE)
      ->set('session_ttl', 86400)
      ->save();
  }

  /**
   * Tests block rendering for anonymous user.
   *
   * @covers ::build
   */
  public function testBlockRenderingAnonymous() {
    // Place the block in content region.
    $this->drupalPlaceBlock('waap_login_block', 'content');

    // Visit homepage.
    $this->drupalGet('<front>');

    // Check that login button is visible.
    $this->assertSession()->elementExists('css', '.waap-login-button');
    $this->assertSession()->elementExists('css', '.waap-login-button__text');
    $this->assertSession()->pageTextContains('Login with WaaP');
  }

  /**
   * Tests block rendering for authenticated user.
   *
   * @covers ::build
   */
  public function testBlockRenderingAuthenticated() {
    // Create a user and log in.
    $user = $this->createUser([
      'name' => 'waap_test_user',
      'mail' => 'test@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'status' => 1,
    ]);
    $this->drupalLogin($user);

    // Place the block in content region.
    $this->drupalPlaceBlock('waap_login_block', 'content');

    // Visit homepage.
    $this->drupalGet('<front>');

    // Check that logout button is visible.
    $this->assertSession()->elementExists('css', '.waap-logout-button');
    $this->assertSession()->pageTextContains('waap_test_user');
  }

  /**
   * Tests block not rendering when module is disabled.
   *
   * @covers ::build
   */
  public function testBlockNotRenderingWhenDisabled() {
    // Disable WaaP module.
    $this->config('waap_login.settings')
      ->set('enabled', FALSE)
      ->save();

    // Place the block in content region.
    $this->drupalPlaceBlock('waap_login_block', 'content');

    // Visit homepage.
    $this->drupalGet('<front>');

    // Check that block is not rendered.
    $this->assertSession()->elementNotExists('css', '.waap-login-button');
    $this->assertSession()->elementNotExists('css', '.waap-logout-button');
  }

  /**
   * Tests block rendering with user without permission.
   *
   * @covers ::build
   */
  public function testBlockNotRenderingWithoutPermission() {
    // Create a user without permission.
    $user = $this->createUser([
      'name' => 'no_permission_user',
      'mail' => 'no_permission@example.com',
      'status' => 1,
    ]);
    $this->drupalLogin($user);

    // Revoke permission.
    $this->drupalGet('admin/people/' . $user->id() . '/permissions');
    $this->submitForm([
      'user_permissions[use waap authentication][use waap authentication]' => FALSE,
    ]);
    $this->submitForm([], 'Save permissions');

    // Place the block in content region.
    $this->drupalPlaceBlock('waap_login_block', 'content');

    // Visit homepage.
    $this->drupalGet('<front>');

    // Check that block is not rendered.
    $this->assertSession()->elementNotExists('css', '.waap-login-button');
    $this->assertSession()->elementNotExists('css', '.waap-logout-button');
  }

  /**
   * Tests block attaches correct library.
   *
   * @covers ::build
   */
  public function testBlockLibraryAttachment() {
    // Enable WaaP module.
    $this->config('waap_login.settings')
      ->set('enabled', TRUE)
      ->save();

    // Place the block in content region.
    $this->drupalPlaceBlock('waap_login_block', 'content');

    // Visit homepage.
    $this->drupalGet('<front>');

    // Check that waap_login library is attached.
    $this->assertSession()->elementExists('css', 'script[src*="/modules/waap_login/js/waap-login.js"]');
  }

  /**
   * Tests block passes drupalSettings to JavaScript.
   *
   * @covers ::build
   */
  public function testBlockDrupalSettings() {
    // Enable WaaP module.
    $this->config('waap_login.settings')
      ->set('enabled', TRUE)
      ->set('use_staging', FALSE)
      ->set('authentication_methods', ['email', 'social', 'wallet'])
      ->set('allowed_socials', ['google', 'twitter', 'discord'])
      ->set('walletconnect_project_id', 'test_project_id')
      ->set('enable_dark_mode', TRUE)
      ->set('show_secured_badge', TRUE)
      ->set('require_email_verification', FALSE)
      ->set('require_username', FALSE)
      ->set('auto_create_users', TRUE)
      ->set('session_ttl', 86400)
      ->save();

    // Place the block in content region.
    $this->drupalPlaceBlock('waap_login_block', 'content');

    // Visit homepage.
    $this->drupalGet('<front>');

    // Check that drupalSettings is passed to JavaScript.
    $settings = $this->getSession()->evaluate('return (typeof Drupal === "undefined" ? undefined : Drupal.settings)');
    $this->assertIsArray($settings);
    $this->assertArrayHasKey('waap_login', $settings);
    $this->assertEquals('test_project_id', $settings['waap_login']['walletconnect_project_id']);
  }

  /**
   * Tests block cache contexts.
   *
   * @covers ::getCacheContexts
   */
  public function testBlockCacheContexts() {
    $block = $this->container->get('plugin.manager.block')->createInstance('waap_login_block');

    $this->assertIsArray($block->getCacheContexts());
    $this->assertContains('user.roles:anonymous', $block->getCacheContexts());
  }

  /**
   * Tests block cache tags.
   *
   * @covers ::getCacheTags
   */
  public function testBlockCacheTags() {
    $block = $this->container->get('plugin.manager.block')->createInstance('waap_login_block');

    $this->assertIsArray($block->getCacheTags());
    $this->assertContains('config:waap_login.settings', $block->getCacheTags());
  }

  /**
   * Tests block cache max age.
   *
   * @covers ::getCacheMaxAge
   */
  public function testBlockCacheMaxAge() {
    $block = $this->container->get('plugin.manager.block')->createInstance('waap_login_block');

    $this->assertEquals(0, $block->getCacheMaxAge());
  }

  /**
   * Tests block for authenticated user shows wallet address.
   *
   * @covers ::build
   */
  public function testBlockShowsWalletAddress() {
    // Create a user with wallet address.
    $user = $this->createUser([
      'name' => 'waap_user_with_wallet',
      'mail' => 'wallet@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'status' => 1,
    ]);
    $this->drupalLogin($user);

    // Place the block in content region.
    $this->drupalPlaceBlock('waap_login_block', 'content');

    // Visit homepage.
    $this->drupalGet('<front>');

    // Check that wallet address is displayed.
    $this->assertSession()->elementExists('css', '.waap-user-info__address');
    $this->assertSession()->pageTextContains('0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb');
  }

  /**
   * Tests block for authenticated user shows user email.
   *
   * @covers ::build
   */
  public function testBlockShowsUserEmail() {
    // Create a user with email.
    $user = $this->createUser([
      'name' => 'waap_user_with_email',
      'mail' => 'user@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'status' => 1,
    ]);
    $this->drupalLogin($user);

    // Place the block in content region.
    $this->drupalPlaceBlock('waap_login_block', 'content');

    // Visit homepage.
    $this->drupalGet('<front>');

    // Check that user email is displayed.
    $this->assertSession()->elementExists('css', '.waap-user-info__email');
    $this->assertSession()->pageTextContains('user@example.com');
  }

  /**
   * Tests block for authenticated user shows profile URL.
   *
   * @covers ::build
   */
  public function testBlockShowsProfileUrl() {
    // Create a user.
    $user = $this->createUser([
      'name' => 'waap_test_user',
      'mail' => 'test@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'status' => 1,
    ]);
    $this->drupalLogin($user);

    // Place the block in content region.
    $this->drupalPlaceBlock('waap_login_block', 'content');

    // Visit homepage.
    $this->drupalGet('<front>');

    // Check that profile URL is present.
    $this->assertSession()->elementExists('css', 'a[href*="/user/' . $user->id() . '"]');
  }

  /**
   * Tests block template rendering with custom configuration.
   *
   * @covers ::build
   */
  public function testBlockTemplateWithCustomConfig() {
    // Enable WaaP module with custom config.
    $this->config('waap_login.settings')
      ->set('enabled', TRUE)
      ->set('use_staging', TRUE)
      ->set('authentication_methods', ['email'])
      ->set('allowed_socials', ['google'])
      ->set('walletconnect_project_id', 'custom_project_id')
      ->set('enable_dark_mode', FALSE)
      ->set('show_secured_badge', FALSE)
      ->save();

    // Place the block in content region.
    $this->drupalPlaceBlock('waap_login_block', 'content');

    // Visit homepage.
    $this->drupalGet('<front>');

    // Check that only email method is shown.
    $this->assertSession()->elementExists('css', '.waap-login-button');
    $this->assertSession()->elementNotExists('css', '.waap-login-button__text'); // No "Connect using" text
    $this->assertSession()->pageTextContains('Login with WaaP'); // Just button text
  }

  /**
   * Tests block with dark mode enabled.
   *
   * @covers ::build
   */
  public function testBlockDarkModeEnabled() {
    // Enable WaaP module with dark mode.
    $this->config('waap_login.settings')
      ->set('enabled', TRUE)
      ->set('enable_dark_mode', TRUE)
      ->save();

    // Place the block in content region.
    $this->drupalPlaceBlock('waap_login_block', 'content');

    // Visit homepage.
    $this->drupalGet('<front>');

    // Check that dark mode class is present.
    $this->assertSession()->elementExists('css', '.waap-login-wrapper--dark-mode');
  }

  /**
   * Tests block with secured badge enabled.
   *
   * @covers ::build
   */
  public function testBlockSecuredBadgeEnabled() {
    // Enable WaaP module with secured badge.
    $this->config('waap_login.settings')
      ->set('enabled', TRUE)
      ->set('show_secured_badge', TRUE)
      ->save();

    // Place the block in content region.
    $this->drupalPlaceBlock('waap_login_block', 'content');

    // Visit homepage.
    $this->drupalGet('<front>');

    // Check that secured badge is present.
    $this->assertSession()->elementExists('css', '.waap-secured-badge');
  }

}
