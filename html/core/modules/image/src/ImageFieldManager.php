<?php

namespace Drupal\image;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Image\ImageInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Provides a service for managing image fields.
 *
 * @todo perhaps rename to ImageDefaultAccess and make getDefaultImageFields() protected?
 *   That is, this will be a service only for checking access to images that are used by default,
 *   because managing fields is definitely not what this service does.
 */
class ImageFieldManager implements ImageFieldManagerInterface {

  /**
   * Initialized field cache for default images.
   *
   * @var array<string, \Drupal\Core\Field\FieldDefinitionInterface[]>
   */
  protected array $cachedDefaults;

  /**
   * Constructs a new ImageFieldManager.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(
    #[Autowire(service: 'cache.default')]
    protected readonly CacheBackendInterface $cache,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityRepositoryInterface $entityRepository,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getDefaultImageFields(): array {
    if (!isset($this->cachedDefaults)) {
      $cid = 'image:default_images';
      if ($cache = $this->cache->get($cid)) {
        $this->cachedDefaults = $cache->data;
      }
      else {
        // Save a map of all default image UUIDs and their corresponding field
        // definitions for quick lookup.
        $defaults = [];
        $field_map = $this->entityFieldManager->getFieldMapByFieldType('image');
        $cache_tags = [
          'image_default_images',
          'entity_field_info',
        ];
        foreach ($field_map as $entity_type_id => $fields) {
          $field_storages = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id);
          foreach ($fields as $field_name => $field_info) {
            // First, check if the default image is set on the field storage.
            $uri_from_storage = NULL;
            $file_uuid = $field_storages[$field_name]->getSetting('default_image')['uuid'];
            if ($file_uuid && $file = $this->entityRepository->loadEntityByUuid('file', $file_uuid)) {
              /** @var \Drupal\file\FileInterface $file */
              $uri_from_storage = $file->getFileUri();
              $cache_tags = Cache::mergeTags($cache_tags, $file->getCacheTags());
            }

            foreach ($field_info['bundles'] as $bundle) {
              $field_definition = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle)[$field_name];
              $default_uri = $uri_from_storage;
              $file_uuid = $field_definition->getSetting('default_image')['uuid'];
              // If the default image is overridden in the field definition, use
              // that instead of the one set on the field storage.
              if ($file_uuid && $file = $this->entityRepository->loadEntityByUuid('file', $file_uuid)) {
                /** @var \Drupal\file\FileInterface $file */
                $default_uri = $file->getFileUri();
                $cache_tags = Cache::mergeTags($cache_tags, $file->getCacheTags());
              }
              // Finally, if a default image URI was found, add it to the list.
              if ($default_uri) {
                $defaults[$default_uri][] = $field_definition;
              }
            }
          }
        }
        // Cache the default image list.
        $this->cachedDefaults = $defaults;
        $this->cache->set($cid, $defaults, CacheBackendInterface::CACHE_PERMANENT, $cache_tags);
      }
    }
    return $this->cachedDefaults;
  }

  /**
   * {@inheritdoc}
   */
  public function checkAccessToDefaultImage(ImageInterface $image, ?AccountInterface $account = NULL): AccessResultInterface {
    if (!$image->isValid()) {
      return AccessResult::forbidden();
    }
    // If the image being requested for download is being used as the default
    // image for any fields, then grant access if the user has 'view' access to
    // at least one of those fields.
    $uri = $image->getSource();
    $default_images = $this->getDefaultImageFields();
    $access = AccessResult::neutral()->addCacheTags(['image_default_images', 'entity_field_info']);
    $account ??= $this->currentUser;
    if (isset($default_images[$uri])) {
      foreach ($default_images[$uri] as $field_definition) {
        $access_control_handler = $this->entityTypeManager->getAccessControlHandler($field_definition->getTargetEntityTypeId());
        $field_access = $access_control_handler->fieldAccess('view', $field_definition, $account, NULL, TRUE);
        // As long as the user has view access to at least one of the fields,
        // that uses this image as a default, we can exit this foreach loop,
        // and grant access.
        if ($field_access->isAllowed()) {
          return AccessResult::allowed()
            ->addCacheableDependency($access)
            ->addCacheableDependency($field_access);
        }
      }
    }
    return $access;
  }

}
