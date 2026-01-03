<?php

namespace Drupal\image;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Image\ImageInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides an interface for an image field manager.
 */
interface ImageFieldManagerInterface {

  /**
   * The default image directory.
   */
  public const DEFAULT_IMAGE_DIRECTORY = 'default_images';

  /**
   * Map default values for image fields, and those fields' definitions.
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface[]>
   *   An associative array, where the keys are image file URIs, and the values
   *   are arrays of field definitions which use that image file as their
   *   default image.
   */
  public function getDefaultImageFields(): array;

  /**
   * Check access to a default image.
   *
   * This is used in image_file_download() to determine whether to grant access
   * to an image stored in the private file storage.
   *
   * @param \Drupal\Core\Image\ImageInterface $image
   *   The image to check access to.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   (optional) The account for which to check access.
   *   Defaults to the current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function checkAccessToDefaultImage(ImageInterface $image, ?AccountInterface $account = NULL): AccessResultInterface;

}
