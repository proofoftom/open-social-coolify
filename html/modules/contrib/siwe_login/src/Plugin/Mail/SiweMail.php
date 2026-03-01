<?php

namespace Drupal\siwe_login\Plugin\Mail;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Defines the SIWE Login mail plugin.
 *
 * @Mail(
 *   id = "siwe_login",
 *   label = @Translation("SIWE Login mailer"),
 *   description = @Translation("Sends emails for SIWE Login module.")
 * )
 */
class SiweMail implements MailInterface {

  /**
   * The mail plugin manager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected $mailManager;

  use StringTranslationTrait;

  /**
   * Constructs a new SiweMail instance.
   *
   * @param \Drupal\Component\Plugin\PluginManagerInterface $mail_manager
   *   The mail plugin manager.
   */
  public function __construct(PluginManagerInterface $mail_manager) {
    $this->mailManager = $mail_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function format(array $message) {
    // Join the body array into one string.
    $message['body'] = implode("\n\n", $message['body']);
    // Convert any HTML to plain-text.
    $message['body'] = trim(strip_tags($message['body']));
    // Wrap the mail body for sending.
    $message['body'] = wordwrap($message['body'], 77);
    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message) {
    // Get the default mailer.
    $default_mailer = $this->mailManager->createInstance('php_mail');
    // Send the email using the default mailer.
    return $default_mailer->mail($message);
  }

}
