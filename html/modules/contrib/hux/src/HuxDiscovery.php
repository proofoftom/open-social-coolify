<?php

declare(strict_types=1);

namespace Drupal\hux;

use Drupal\hux\Attribute\Alter;
use Drupal\hux\Attribute\Hook;
use Drupal\hux\Attribute\OriginalInvoker;
use Drupal\hux\Attribute\ReplaceOriginalHook;
use Psr\Container\ContainerInterface;

/**
 * Hux discovery.
 *
 * Discovers hooks, hook replacements, and alters in tagged services. This class
 * makes it easier to implement hook caching, potentially eliminating the need
 * to initialize some hook classes which are not utilized. Switching off caching
 * allows developers to quickly add new hooks to hook classes without the need
 * to clear the entire cache.
 *
 * @internal
 *   For internal use only, behavior and serialized data structure may change at
 *   any time.
 */
final class HuxDiscovery {

  /**
   * @var array<class-string, array<mixed>>
   */
  protected array $discovery = [];

  private ?array $implementations = NULL;

  /**
   * Constructs a new HuxDiscovery.
   *
   * @param array<string, array{string, string, string}> $implementations
   *   An array of module names and class names keyed by service ID.
   */
  public function __construct(array $implementations) {
    $this->implementations = $implementations;
  }

  /**
   * Discovers hook implementations in hook classes.
   *
   * @param \Psr\Container\ContainerInterface $container
   *   The service locator.
   *
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
   *   If a service implementation was added but was removed unexpectedly.
   */
  public function discovery(ContainerInterface $container): void {
    if (!isset($this->implementations)) {
      throw new \Exception('Hook implementations were cleared after serialization. Re-construct the discovery class.');
    }

    $this->discovery = [];

    foreach ($this->implementations as [$moduleName, $className, $serviceId]) {
      $reflectionClass = new \ReflectionClass($className ?? $container->get($serviceId));
      $methods = $reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC);

      foreach ($methods as $reflectionMethod) {
        $methodName = $reflectionMethod->getName();

        $attributesHooks = $reflectionMethod->getAttributes(Hook::class);
        foreach ($attributesHooks as $attribute) {
          $instance = $attribute->newInstance();
          assert($instance instanceof Hook);
          $this->discovery[Hook::class][$instance->hook][] = [
            $serviceId,
            $instance->moduleName ?? $moduleName,
            $methodName,
            $instance->priority,
          ];
        }

        $attributesHookReplacements = $reflectionMethod->getAttributes(ReplaceOriginalHook::class);
        foreach ($attributesHookReplacements as $attribute) {
          $instance = $attribute->newInstance();
          assert($instance instanceof ReplaceOriginalHook);

          // Original invoker positions.
          /** @var int[] $originalInvokerPositions */
          $originalInvokerPositions = [];

          // Support legacy attribute parameter.
          if ($instance->originalInvoker === TRUE) {
            // Legacy behavior added original invoker to first parameter.
            $originalInvokerPositions[] = 0;
          }

          $parameters = $reflectionMethod->getParameters();
          foreach ($parameters as $parameter) {
            $attributes = $parameter->getAttributes(OriginalInvoker::class);
            if (count($attributes) > 0) {
              $originalInvokerPositions[] = $parameter->getPosition();
            }
          }

          $this->discovery[ReplaceOriginalHook::class][$instance->hook][] = [
            $serviceId,
            $instance->moduleName,
            $methodName,
            $originalInvokerPositions,
          ];
        }

        $attributesAlters = $reflectionMethod->getAttributes(Alter::class);
        foreach ($attributesAlters as $attribute) {
          $instance = $attribute->newInstance();
          assert($instance instanceof Alter);
          $this->discovery[Alter::class][$instance->alter][] = [
            $serviceId,
            $methodName,
          ];
        }
      }
    }
  }

  /**
   * Get all Hux implementations of a hook.
   *
   * @param string $hook
   *   A hook.
   *
   * @return \Generator<array{string, string, string, int}>
   *   A generator yielding an array of service ID, module name, method name,
   *   and priority.
   */
  public function getHooks(string $hook): \Generator {
    yield from $this->discovery[Hook::class][$hook] ?? [];
  }

  /**
   * Get all Hux implementations of replacement hooks.
   *
   * @param string $hook
   *   A hook.
   *
   * @return \Generator<array{string, string, string, int[]}>
   *   A generator yielding an array of service ID, module name, method name,
   *   and flag for whether the original implementation should be passed as a
   *   callable as first parameter.
   */
  public function getHookReplacements(string $hook): \Generator {
    yield from $this->discovery[ReplaceOriginalHook::class][$hook] ?? [];
  }

  /**
   * Get all Hux implementations of alters hooks.
   *
   * @param string $alter
   *   An alter.
   *
   * @return \Generator<array{string, string, string}>
   *   A generator yielding an array of service ID,  method name.
   */
  public function getAlters(string $alter): \Generator {
    yield from $this->discovery[Alter::class][$alter] ?? [];
  }

  /**
   * Optimizes the object by removing $this->implementations.
   */
  public function __sleep(): array {
    return ['discovery'];
  }

}
