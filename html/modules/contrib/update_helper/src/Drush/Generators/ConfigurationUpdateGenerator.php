<?php

namespace Drupal\update_helper\Drush\Generators;

use Drupal\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Update\UpdateHookRegistry;
use Drupal\Core\Update\UpdateRegistry;
use Drupal\update_helper\ConfigHandler;
use Drupal\update_helper\Events\CommandExecuteEvent;
use Drupal\update_helper\Events\CommandInteractEvent;
use Drupal\update_helper\Events\UpdateHelperEvents;
use DrupalCodeGenerator\Validator\Chained;
use DrupalCodeGenerator\Validator\MachineName;
use DrupalCodeGenerator\Validator\Required;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use DrupalCodeGenerator\Asset\AssetCollection;
use Symfony\Component\Console\Question\ChoiceQuestion;

use DrupalCodeGenerator\Attribute\Generator;
use DrupalCodeGenerator\Command\BaseGenerator;
use DrupalCodeGenerator\GeneratorType;

/**
 * Implements update_helper:configuration-update command.
 */
#[Generator(
  name: 'update_helper:configuration-update',
  description: 'Generates a configuration update',
  aliases: ['config-update'],
  type: GeneratorType::OTHER,
)]
class ConfigurationUpdateGenerator extends BaseGenerator {

  /**
   * {@inheritdoc}
   */
  protected string $name = 'update_helper:configuration-update';

  /**
   * {@inheritdoc}
   */
  protected string $description = 'Generates a configuration update';

  /**
   * {@inheritdoc}
   */
  protected string $alias = 'config-update';

  /**
   * The module extension list service.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $extensionList;

  /**
   * Drupal\update_helper\ConfigHandler definition.
   *
   * @var \Drupal\update_helper\ConfigHandler
   */
  protected $configHandler;

  /**
   * Drupal\Core\Extension\ModuleHandler definition.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * Event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The post update registry.
   *
   * @var \Drupal\Core\Update\UpdateRegistry
   */
  protected UpdateRegistry $postUpdateRegistry;

  /**
   * The update hook registry.
   *
   * @var \Drupal\Core\Update\UpdateHookRegistry
   */
  protected UpdateHookRegistry $updateHookRegistry;

  /**
   * {@inheritdoc}
   */
  public function __construct(ModuleExtensionList $extension_list, EventDispatcherInterface $event_dispatcher, ModuleHandlerInterface $module_handler, ConfigHandler $config_handler, UpdateRegistry $post_update_registry, UpdateHookRegistry $update_hook_registry) {
    parent::__construct();
    $this->extensionList = $extension_list;
    $this->eventDispatcher = $event_dispatcher;
    $this->configHandler = $config_handler;
    $this->moduleHandler = $module_handler;
    $this->postUpdateRegistry = $post_update_registry;
    $this->updateHookRegistry = $update_hook_registry;
  }

  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('extension.list.module'),
      $container->get('event_dispatcher'),
      $container->get('module_handler'),
      $container->get('update_helper.config_handler'),
      $container->get('update.post_update_registry'),
      $container->get('update.update_hook_registry')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function generate(array &$vars, AssetCollection $assets): void {
    $extensions = $this->getExtensions();
    $question = new Question('Enter a module/profile');
    $question->setAutocompleterValues(array_keys($extensions));
    $question->setValidator(function ($module_name) use ($extensions) {
      if (empty($module_name) || !array_key_exists($module_name, $extensions)) {
        throw new \InvalidArgumentException(
          sprintf(
            'The module name "%s" is not valid',
            $module_name
          )
        );
      }
      return $module_name;
    });

    $vars['module'] = $this->io()->askQuestion($question);

    $question = new ChoiceQuestion('Do you want to create a post_update or hook_update_N update function?',
      ['post_update', 'hook_update_N'], 'post_update');
    $update_method = $this->io()->askQuestion($question);

    if ($update_method === 'post_update') {
      $question = new Question('Please enter the machine name for the update', NULL);
      $question->setValidator(new Chained(new Required(), new MachineName()));

      // Number post update hooks for implicit ordering of post update functions
      // created by the Update Helper module. This is because Update Helper uses
      // diffs and therefore requires that it's updates are run in a particular
      // order. The update numbers DO NOT reflect the module schema and start
      // from 0001.
      $updates = array_merge($this->postUpdateRegistry->getUpdateFunctions($vars['module']), array_keys($this->postUpdateRegistry->getRemovedPostUpdates($vars['module'])));
      $lastUpdate = 0;
      foreach ($updates as $update) {
        if (preg_match('/^' . preg_quote($vars['module']) . '_post_update_(\d*)_.*$/', $update, $matches)) {
          $lastUpdate = max($lastUpdate, $matches[1]);
        }
      }
      $lastUpdate = str_pad((string) $lastUpdate + 1, 4, '0', STR_PAD_LEFT);
      $vars['update_name'] = 'post_update_' . $lastUpdate . '_' . $this->io()->askQuestion($question);
    }
    else {
      $lastUpdate = $this->updateHookRegistry->getInstalledVersion($vars['module']);
      $nextUpdate = $lastUpdate > 0 ? ($lastUpdate + 1) : 8001;

      $question = new Question('Please provide the number for update hook to be added', $nextUpdate);
      $question->setValidator(function ($update_number) use ($lastUpdate) {
        if ($update_number === NULL || $update_number === '' || !is_numeric($update_number) || $update_number <= $lastUpdate) {
          throw new \InvalidArgumentException(
            sprintf(
              'The update number "%s" is not valid',
              $update_number
            )
          );
        }
        return $update_number;
      });
      $vars['update_name'] = 'update_' . $this->io()->askQuestion($question);
    }

    $vars['description'] = $this->io()->ask('Please enter a description text for update. This will be used as the comment for update hook.', 'Configuration update.', new Required());

    $enabled_modules = array_filter($this->moduleHandler->getModuleList(), function (Extension $extension) {
      return ($extension->getType() === 'module' || $extension->getType() === 'profile');
    });
    $enabled_modules = array_keys($enabled_modules);

    $question = new ChoiceQuestion('Provide a comma-separated list of modules which configurations should be included in update.', $enabled_modules);
    $question->setMultiselect(TRUE);
    $vars['include-modules'] = $this->io()->askQuestion($question);

    $vars['from-active'] = $this->io()->confirm('Generate update from active configuration in database to configuration in Yml files?');

    // Get additional options provided by other modules.
    $event = new CommandInteractEvent($vars);
    $this->eventDispatcher->dispatch($event, UpdateHelperEvents::COMMAND_GCU_INTERACT);

    foreach ($event->getQuestions() as $key => $question) {
      $vars[$key] = $this->io()->askQuestion($question);
    }

    // Get patch data and save it into file.
    $patch_data = $this->configHandler->generatePatchFile($vars['include-modules'], $vars['from-active']);

    if (!empty($patch_data)) {

      // Get additional options provided by other modules.
      $event = new CommandExecuteEvent($vars);
      $this->eventDispatcher->dispatch($event, UpdateHelperEvents::COMMAND_GCU_EXECUTE);

      foreach ($event->getTemplatePaths() as $path) {
        $this->getHelper('renderer')->registerTemplatePath($path);
      }

      foreach ($event->getAssets() as $asset) {
        $assets[] = $asset;
      }

      $patch_file_path = $this->configHandler->getPatchFile($vars['module'], static::getUpdateFunctionName($vars['module'], $vars['update_name']), TRUE);

      // Add the patch file.
      $assets->addFile($patch_file_path)->content($patch_data);
    }
    else {
      $this->io()->write('There are no configuration changes that should be exported for the update.', TRUE);
    }
  }

  /**
   * Get installed non_core extensions.
   *
   * @return \Drupal\Core\Extension\Extension[]
   *   The list of installed non-core extensions keyed by the extension name.
   */
  protected function getExtensions(): array {
    $extensions = array_filter($this->extensionList->getList(),
      static function ($extension): bool {
        return ($extension->origin !== 'core');
      });

    ksort($extensions);
    return $extensions;
  }

  /**
   * Get update hook function name.
   *
   * @param string $module_name
   *   Module name.
   * @param string $update_name
   *   Update number.
   *
   * @return string
   *   Returns update hook function name.
   */
  public static function getUpdateFunctionName($module_name, $update_name): string {
    return $module_name . '_' . $update_name;
  }

}
