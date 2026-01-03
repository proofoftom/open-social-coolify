<?php

declare(strict_types=1);

namespace Drupal\hux;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Psr\Container\ContainerInterface;

/**
 * Hux module handler.
 *
 * Various invoke methods do not call the inner implementation since we need
 * to be able to override the original implementation, where they would normally
 * delegate invocation to an invoke method on the original class.
 *
 * @internal
 *   All class structure, behavior, etc, may change at any time.
 */
final class HuxModuleHandler implements ModuleHandlerInterface, DestructableInterface {

  use HuxModuleHandlerProxyTrait;

  private HuxDiscovery $discovery;

  /**
   * An array of hook implementations.
   *
   * @var array<string, array{callable, string, int}>
   */
  private array $hooks = [];

  /**
   * Hook replacement callables keyed by hook, then module name.
   *
   * @var array<string, array<string, \Drupal\hux\HuxReplacementHook>>
   */
  private array $hookReplacements;

  /**
   * Alter callables keyed by alter.
   *
   * @var array<string, callable[]>
   */
  private array $alters;

  /**
   * Constructs a new HuxModuleHandler.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface&\Drupal\Core\DestructableInterface $inner
   *   The inner module handler.
   * @param \Psr\Container\ContainerInterface $locator
   *   Service locator for hooks services.
   */
  public function __construct(
    protected ModuleHandlerInterface & DestructableInterface $inner,
    private ContainerInterface $locator,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function invoke($module, $hook, array $args = []) {
    $original = function (&...$args) use ($module, $hook): mixed {
      // If there are Hux implementations, only the last return value
      // will be returned.
      $return = $this->inner->invoke($module, $hook, $args);

      $callback = static function (callable $hookInvoker, string $calledModule) use ($module, $args, &$return): void {
        if ($module === $calledModule) {
          $return = $hookInvoker(...$args);
        }
      };
      $this->invokeHux($hook, $callback);
      return $return;
    };

    $replacements = $this->getOriginalHookReplacementInvokers($hook);
    $replacement = ($replacements[$module] ?? NULL)?->getCallable($original);

    return $replacement
      ? $replacement(...$args)
      : $original(...$args);
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-param callable(callable, string): mixed $callback.
   */
  public function invokeAllWith(string $hook, callable $callback): void {
    $replacements = $this->getOriginalHookReplacementInvokers($hook);
    // Wrap the callback if there are any replacements.
    $callback = function (callable $hookInvoker, string $module) use ($callback, $replacements): void {
      $replacement = $replacements[$module] ?? NULL;
      $hookInvoker = $replacement?->getCallable($hookInvoker) ?? $hookInvoker;
      $callback($hookInvoker, $module);
    };

    $this->inner->invokeAllWith($hook, $callback);
    $this->invokeHux($hook, $callback);
  }

  /**
   * {@inheritdoc}
   */
  public function invokeAll($hook, array $args = []) {
    // Don't use inner so we get the replacement features of our invokeAllWith.
    $return = [];
    $this->invokeAllWith($hook, function (callable $hookInvoker, string $module) use ($args, &$return): void {
      $result = $hookInvoker(...$args);
      if (isset($result) && is_array($result)) {
        $return = NestedArray::mergeDeep($return, $result);
      }
      elseif (isset($result)) {
        $return[] = $result;
      }
    });
    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function invokeDeprecated($description, $module, $hook, array $args = []) {
    // To get replacement features we need to use our invoke method not inner.
    $result = $this->invoke($module, $hook, $args);
    $this->triggerDeprecationError($description, $hook);
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function invokeAllDeprecated($description, $hook, array $args = []) {
    // To get replacement features we need to use our invoke method not inner.
    $result = $this->invokeAll($hook, $args);
    $this->triggerDeprecationError($description, $hook);
    return $result;
  }

  /**
   * Invokes hooks with a callable.
   *
   * @param string $hook
   *   A hook.
   * @param callable $callback
   *   The callback to execute per implementation.
   */
  private function invokeHux(string $hook, callable $callback): void {
    foreach ($this->generateInvokers($hook) as [$hookInvoker, $moduleName]) {
      $callback($hookInvoker, $moduleName);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alter($type, &$data, &$context1 = NULL, &$context2 = NULL): void {
    $this->inner->alter($type, $data, $context1, $context2);

    $types = is_array($type) ? $type : [$type];
    foreach ($types as $alter) {
      foreach ($this->generateAlterInvokers($alter) as $alterInvoker) {
        $alterInvoker($data, $context1, $context2);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alterDeprecated($description, $type, &$data, &$context1 = NULL, &$context2 = NULL): void {
    $this->inner->alterDeprecated($description, $type, $data, $context1, $context2);

    $types = is_array($type) ? $type : [$type];
    foreach ($types as $alter) {
      foreach ($this->generateAlterInvokers($alter) as $alterInvoker) {
        $alterInvoker($data, $context1, $context2);
      }
    }
  }

  /**
   * Generates invokers for a hook.
   *
   * @param string $hook
   *   A hook.
   *
   * @return \Generator<array{callable, string}>
   *   A generator with hook callbacks and other metadata.
   */
  private function generateInvokers(string $hook): \Generator {
    if (isset($this->hooks[$hook])) {
      yield from $this->hooks[$hook];
      return;
    }

    $hooks = [];
    foreach ($this->discovery->getHooks($hook) as [
      $serviceId,
      $moduleName,
      $methodName,
      $priority,
    ]) {
      $service = $this->locator->get($serviceId);
      $hooks[] = [
        \Closure::fromCallable([$service, $methodName]),
        $moduleName,
        $priority,
      ];
    }

    usort($hooks, function (array $a, array $b) {
      [2 => $aPriority] = $a;
      [2 => $bPriority] = $b;
      return $bPriority <=> $aPriority;
    });

    // Wait for all the [sorted] callables before caching.
    $this->hooks[$hook] = $hooks;

    yield from $this->hooks[$hook];
  }

  /**
   * Generates invokers for a hook.
   *
   * @param string $hook
   *   A hook.
   *
   * @return array<string, \Drupal\hux\HuxReplacementHook>
   *   Hook replacement callables keyed by module name.
   */
  private function getOriginalHookReplacementInvokers(string $hook): array {
    if (isset($this->hookReplacements[$hook])) {
      return $this->hookReplacements[$hook];
    }

    $this->hookReplacements[$hook] = [];
    foreach ($this->discovery->getHookReplacements($hook) as [
      $serviceId,
      $moduleName,
      $methodName,
      $originalInvokerPositions,
    ]) {
      $service = $this->locator->get($serviceId);
      $hookInvoker = \Closure::fromCallable([$service, $methodName]);
      $this->hookReplacements[$hook][$moduleName] = new HuxReplacementHook(
        $hookInvoker,
        $originalInvokerPositions,
      );
    }

    return $this->hookReplacements[$hook];
  }

  /**
   * Generates invokers for an alter.
   *
   * @param string $alter
   *   An alter.
   *
   * @return \Generator<array{callable, string}>
   *   A generator with hook callbacks and other metadata.
   */
  private function generateAlterInvokers(string $alter): \Generator {
    if (isset($this->alters[$alter])) {
      yield from $this->alters[$alter];
      return;
    }

    $this->alters[$alter] = [];
    foreach ($this->discovery->getAlters($alter) as [$serviceId, $methodName]) {
      $service = $this->locator->get($serviceId);
      $this->alters[$alter][] = \Closure::fromCallable([$service, $methodName]);
    }

    yield from $this->alters[$alter];
  }

  /**
   * Triggers discovery when optimize mode is not enabled.
   *
   * This method is invoked as a method call, added in HuxCompilerPass.
   *
   * @param array<string, array{string, string, string}> $implementations
   *   An array of module names keyed by service ID.
   *
   * @internal
   *   For internal use only.
   */
  public function unoptimizedDiscovery(array $implementations): void {
    $this->discovery = new HuxDiscovery($implementations);
    $this->discovery->discovery($this->locator);
  }

  /**
   * Sets the serialized discovery data from the container.
   *
   *  This method is invoked as a method call, added in HuxCompilerPass.
   *
   * @internal
   *   For internal use only.
   */
  public function setOptimizedDiscovery(string $serializedHuxDiscovery): void {
    $huxDiscovery = unserialize($serializedHuxDiscovery);
    assert($huxDiscovery instanceof HuxDiscovery);
    $this->discovery = $huxDiscovery;
  }

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    $this->inner->destruct();
  }

}
