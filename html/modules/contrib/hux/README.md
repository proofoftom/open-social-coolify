Hux

# About

Hux is a project specifically designed for developers, allowing hook
implementations without needing to define a .module file or any kind of proxy
class/service features.

There are a [few][project-hook_event_dispatcher] [projects][project-hooks]
[out][project-entity_events] there that try to introduce an event subscriber
driven way of working.
Hux is an in between solution, allowing the full benefits of dependency
injection and class driven logic without going fully in with events.
Methods can have the same signature as original hook implementations.
Discovery is automatic, only requiring a hook class to be registered as a
tagged Drupal service and initial cache clear.

Other features

 - Multiple hook implementations per module!
 - Overriding original hook implementations is possible using the
   `[#ReplaceOriginalHook]` annotation.
 - Supports alters.

# Installation

 1. Install as normally.
 2. No additional steps if running Drupal 9.4 or later. If using Drupal 9.3, use
    a patch core from https://www.drupal.org/project/drupal/issues/2616814

# Usage

Create a class in the directory/namespace:

 - File: `src/Hooks/MyModuleHooks.php`
 - Namespace: `Drupal\Hooks\MyModuleHooks`

Once at least one hook has been added to the class, just clear the site cache.

Tip: You do not need to clear the site cache to add more hook implementations!

And in the class file:

```php
declare(strict_types=1);

namespace Drupal\my_module\Hooks;

use Drupal\hux\Attribute\Alter;
use Drupal\hux\Attribute\Hook;
use Drupal\hux\Attribute\OriginalInvoker;
use Drupal\hux\Attribute\ReplaceOriginalHook;

/**
 * Usage examples.
 */
final class MyModuleHooks {

  public function __construct(
    // Autowiring is enabled.
    // \Drupal\Core\DependencyInjection\ContainerInjectionInterface, or manual
    // service definitions are also for older Drupal installs or usage of
    // non-autowirable services.
    private readonly \Drupal\Component\Datetime\TimeInterface $time,
  )

  #[Hook('entity_access')]
  public function myEntityAccess(EntityInterface $entity, string $operation, AccountInterface $account): AccessResultInterface {
    // A barebones implementation.
    return AccessResult::neutral();
  }

  #[Hook('entity_access', priority: 100)]
  public function myEntityAccess2(EntityInterface $entity, string $operation, AccountInterface $account): AccessResultInterface {
    // You can set priority if you have multiple of the same hook!
    return AccessResult::neutral();
  }

  #[Hook('entity_access', moduleName: 'a_different_module', priority: 200)]
  public function myEntityAccess3(EntityInterface $entity, string $operation, AccountInterface $account): AccessResultInterface {
    // You can masquerade as a different module!
    return AccessResult::neutral();
  }

  #[ReplaceOriginalHook(hook: 'entity_access', moduleName: 'media')]
  public function myEntityAccess4(EntityInterface $entity, string $operation, AccountInterface $account): AccessResultInterface {
    // You can override hooks for other modules! E.g \media_entity_access()
    return AccessResult::neutral();
  }

  #[ReplaceOriginalHook(hook: 'entity_access', moduleName: 'media')]
  public function myEntityAccess5(EntityInterface $entity, string $operation, AccountInterface $account, #[OriginalInvoker] callable $originalInvoker): AccessResultInterface {
    // If you override a hook for another module, you can have the original
    // implementation passed to you as a callable!
    $originalResult = $originalInvoker($entity, $operation, $account);
    // Do something...
    return AccessResult::neutral();
  }

  #[Alter('user_format_name')]
  public function myCustomAlter(string &$name, AccountInterface $account): void {
    $name .= ' altered!';
  }

  #[
    Hook('entity_insert'),
    Hook('entity_delete'),
  ]
  public function myEntityAccess(EntityInterface $entity): void {
    // Associate with multiple!
    // Also works with Alters and Replacements.
    return AccessResult::neutral();
  }

}
```

The project makes use of PHP annotations. As of this writing Drupal's code
sniffs don't work that great with PHP 8.0/8.1 features, you can use the patch
at https://www.drupal.org/project/coder/issues/3250346 to appease code sniffer.

Working examples of all Hux features can be found in included tests.

# Optional configuration

## Hooks classes outside of Hooks namespace/directory

Hooks will be automatically discovered in the Hooks namespace, however you can
register a class outside this directory by specifying a service.

Add an entry to your modules' services.yml file. The entry simply needs to be a
public service, with a class and the 'hooks' tag.

```yaml
services:
  my_module.hooks:
    class: Drupal\my_module\MyModuleHooks
    tags:
      - { name: hooks }
```

Then clear the site cache.

## Optimized mode

Hux' [optimized mode][optimized-mode] provides an option geared towards being
developer friendly or optimized for production use. By default this mode is off,
but it should be turned on in production for small gains in performance.

To control whether Hux optimized mode is on or off, add to your `services.yml`:

```yaml
parameters:
  hux:
    optimize: true
```

# License

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public
License as published by the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program; if not, write to the Free
Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.

 [project-hook_event_dispatcher]: https://www.drupal.org/project/hook_event_dispatcher
 [project-hooks]: https://www.drupal.org/project/hooks
 [project-entity_events]: https://www.drupal.org/project/entity_events
 [optimized-mode]: https://www.drupal.org/docs/contributed-modules/hux/hux-optimized-mode
