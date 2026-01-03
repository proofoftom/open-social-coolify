<?php

namespace Drupal\Tests\views_ef_fieldset\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Assert that the views_ef_fieldset module installed correctly.
 *
 * For example, if parts of the views_ef_fieldset config schema used in the
 * views.view.test_views_ef_fieldset view in the test_views_ef_fieldset are
 * missing, then the module will not install correctly. Note that Unit and
 * Kernel tests don't fully install the module.
 *
 * This test can be deleted as soon as there is at least one other Functional
 * or FunctionalJavascript test that installs the test_views_ef_fieldset module
 * during its setUp phase.
 *
 * @group views_ef_fieldset
 */
class ModuleInstallTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['test_views_ef_fieldset'];

  /**
   * Assert that the views_ef_fieldset module installed correctly.
   */
  public function testModuleInstalls() {
    // If we get here, then the module was successfully installed during the
    // setUp phase without throwing any Exceptions. Assert that TRUE is true,
    // so at least one assertion runs, and then exit.
    $this->assertTrue(TRUE, 'Module installed correctly.');
  }

}
