<?php

namespace Drupal\samlauth\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\views\Plugin\views\field\LinkBase;
use Drupal\views\ResultRow;

/**
 * Field handler to present a link to delete an authmap entry.
 *
 * Stripped down version of EntityLinkBase. Crude; Views integration will be
 * improved upon once (hopefully) moved into the externalauth module.
 * Actually I'm not thrilled about using LinkBase because it still contains a
 * lot of entity magic that we don't need, and we likely don't need a lot of
 * its alter options either.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("samlauth_link_delete")
 */
class AuthmapDeleteLink extends LinkBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $row) {
    // This whole link is a quick hack because this functionality should move
    // into the externalauth module. We'll do the access checks in the form.
    return ['#markup' => $this->renderLink($row)];
  }

  /**
   * {@inheritdoc}
   */
  protected function renderLink(ResultRow $row) {
    if ($this->options['output_url_as_text']) {
      return $this->getUrlInfo($row)->toString();
    }
    return parent::renderLink($row);
  }

  /**
   * {@inheritdoc}
   */
  protected function getUrlInfo(ResultRow $row) {
    // We can get away with just passing the UID as an argument, because the
    // combination of uid+provider is unique. In the future we might have
    // storage where multiple authnames from the same provider could be linked
    // to 1 account; if that happens, we won't want to add the authname into
    // the URL directly but Crypt::hmacBase64() it so that it isn't present in
    // referer headers. (The confirm form will need to recalculate the hash(es)
    // in order to check which authname we're talking about - but assuming the
    // number of authnames per UID is low, that won't be too expensive.)
    return Url::fromRoute('samlauth.authmap_delete_form', ['uid' => $row->authmap_uid]);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultLabel() {
    return $this->t('delete');
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    // Copy from EntityLinkBase. Likely unnecessary, but harmless.
    $options = parent::defineOptions();
    $options['output_url_as_text'] = ['default' => FALSE];
    $options['absolute'] = ['default' => FALSE];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    // Copy from EntityLinkBase. Likely unnecessary, but harmless.
    $form['output_url_as_text'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Output the URL as text'),
      '#default_value' => $this->options['output_url_as_text'],
    ];
    $form['absolute'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use absolute link (begins with "http://")'),
      '#default_value' => $this->options['absolute'],
      '#description' => $this->t('Enable this option to output an absolute link. Required if you want to use the path as a link destination.'),
    ];
    parent::buildOptionsForm($form, $form_state);
    // Only show the 'text' field if we don't want to output the raw URL.
    $form['text']['#states']['visible'][':input[name="options[output_url_as_text]"]'] = ['checked' => FALSE];
  }

}
