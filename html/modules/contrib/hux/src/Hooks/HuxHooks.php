<?php

declare(strict_types=1);

namespace Drupal\hux\Hooks;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\hux\Attribute\Hook;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Hux for Hux for Hux for Hux for Hux for Hux for Hux for Hux for Hux for Hux.
 *
 * Eat your tail 🐍.
 */
final class HuxHooks implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Constructs Hooks for Hux.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   * @param string $environment
   *   The app environment.
   * @param array{optimize: bool} $huxParameters
   *   Container parameters for Hux.
   */
  public function __construct(
    TranslationInterface $stringTranslation,
    private string $environment,
    private array $huxParameters,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('string_translation'),
      $container->getParameter('kernel.environment'),
      $container->getParameter('hux'),
    );
  }

  /**
   * Implements hook_requirements().
   *
   * @see \hook_requirements()
   */
  #[Hook('requirements')]
  public function requirements(string $phase): array {
    if ($phase !== 'runtime') {
      return [];
    }

    ['optimize' => $optimize] = $this->huxParameters;

    $tArgs = [':hux_optimized_mode' => 'https://www.drupal.org/docs/contributed-modules/hux/hux-optimized-mode'];
    $requirements['hux.optimize']['title'] = $this->t('Hux');
    $requirements['hux.optimize']['severity'] = $optimize ? \REQUIREMENT_OK : \REQUIREMENT_WARNING;
    $requirements['hux.optimize']['value'] = match (TRUE) {
      $optimize === TRUE => $this->t('Hux is running in <a href=":hux_optimized_mode">optimized mode</a>. This mode can be switched off in development environments.', $tArgs),
      'prod' === $this->environment => $this->t('It is recommended to run Hux in <a href=":hux_optimized_mode">optimized mode</a> when deployed to production environments. This warning can be ignored on development environments.', $tArgs),
      default => $this->t('It is recommended to run Hux in <a href=":hux_optimized_mode">optimized mode</a>. This warning can be ignored on development environments.', $tArgs),
    };

    return $requirements;
  }

}
