<?php

namespace Drupal\responsive_table_filter\Plugin\Filter;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Template\Attribute;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;

/**
 * Provides a filter that wraps <table> tags with a <figure> tag.
 *
 * @Filter(
 *   id = "filter_responsive_table",
 *   title = @Translation("Responsive Table filter"),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_REVERSIBLE,
 *   settings = {
 *     "wrapper_element" = "figure",
 *     "wrapper_classes" = "responsive-figure-table"
 *   }
 * )
 */
class FilterResponsiveTable extends FilterBase {

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $form['wrapper_element'] = [
      '#type' => 'textfield',
      '#required' => TRUE,
      '#title' => $this->t('Wrapper element'),
      '#default_value' => $this->settings['wrapper_element'],
      '#description' => $this->t('The element to wrap the responsive table (e.g. figure)'),
    ];
    $form['wrapper_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Wrapper class(es)'),
      '#default_value' => $this->settings['wrapper_classes'],
      '#description' => $this->t("Any wrapper class(es) separated by spaces (e.g. responsive-figure-table)"),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode): FilterProcessResult {
    $result = new FilterProcessResult($text);
    $text = preg_replace_callback('@<table([^>]*)>(.+?)</table>@s', [
      $this,
      'processTableCallback',
    ], $text);

    $result->setProcessedText($text);
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE, $context = []) {
    return $this->t('Wraps a %table tags with a %figure tag.', [
      '%table' => '<table>',
      '%figure' => '<' . $this->getWrapperElement() . '>',
    ]);
  }

  /**
   * Callback to replace content of the <table> elements.
   */
  private function processTableCallback(array $matches): string {
    $attributes = $matches[1];
    $text = $matches[2];
    return '<' . $this->getWrapperElement() . $this->getWrapperAttributes() . '><table' . $attributes . '>' . $text . '</table></' . $this->getWrapperElement() . '>';
  }

  /**
   * Get the wrapper HTML element.
   */
  private function getWrapperElement(): string {
    return Xss::filter($this->settings['wrapper_element']);
  }

  /**
   * Get the wrapper CSS class(es) and set attributes.
   */
  private function getWrapperAttributes(): Attribute {
    return new Attribute([
      'class' => [$this->settings['wrapper_classes']],
      'tabindex' => '0',
      'aria-label' => $this->t('Scrollable table'),
    ]);
  }

}
