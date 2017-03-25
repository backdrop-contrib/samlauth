<?php

namespace Drupal\samlauth_usersync\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class \Drupal\samlauth\Form\SamlauthUserSettingsForm.
 */
class SamlauthUsersyncSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'samlauth_user_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'samlauth.user.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('samlauth.user.settings');

    $form['user_mapping'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('User Mapping'),
      '#tree' => TRUE,
    ];
    $form['user_mapping']['attributes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('IDP Attributes'),
      '#description' => $this->t('Input available assertion attributes defined
        by the IDP. <br/> <strong>Note:</strong> Only one IDP attribute
        per line.'),
      '#default_value' => $config->get('user_mapping.attributes'),
    ];

    $form['account'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Account'),
      '#tree' => TRUE,
    ];

    $form['account']['linking'] = [
      '#type' => 'details',
      '#title' => $this->t('Linking'),
      '#description' => $this->t('Attempt to link an existing Drupal user based on SAML assertion attributes.'),
      '#tree' => TRUE,
    ];
    $form['account']['linking']['conjunction'] = [
      '#type' => 'select',
      '#title' => $this->t('Conjunction'),
      '#description' => $this->t('Select the conjunction to use when more than one attribute is selected.'),
      '#options' => [
        'OR' => $this->t('OR'),
        'AND' => $this->t('AND'),
      ],
      '#default_value' => $config->get('account.linking.conjunction'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $attributes = $form_state->getValue(['user_mapping', 'attributes']);

    // Check if the user mapping attributes are in use.
    if ($in_use_attributes = $this->attributesAreInUse($attributes)) {

      foreach ($in_use_attributes as $attribute_name => $mapping_keys) {
        $form_state->setErrorByName('user_mapping][attributes',
          $this->t('The "@attribute_name" attribute is in use by: @mapping_keys. Remove all associations before removing the attribute.', [
              '@attribute_name' => $attribute_name,
              '@mapping_keys' => implode(', ', $mapping_keys),
            ]
          )
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('samlauth.user.settings')
      ->setData($form_state->cleanValues()->getValues())
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Attributes that are in use by the user mapping.
   *
   * @param array $attributes
   *   An array of attributes to verify.
   *
   * @return array
   *   An array of attributes that are in use.
   */
  protected function attributesAreInUse($attributes) {
    $original_attributes = $this
      ->config('samlauth.user.settings')
      ->get('user_mapping.attributes');

    $removed_attributes = array_diff(
      explode("\r\n", $original_attributes), explode("\r\n", $attributes)
    );
    $attribute_in_use = [];

    foreach ($this->config('samlauth.user.mapping')->get('user_mapping') as $field_name => $mapping) {
      if (!isset($mapping['attribute']) || empty($mapping['attribute'])) {
        continue;
      }
      $attribute = $mapping['attribute'];

      if (in_array($attribute, $removed_attributes)) {
        $attribute_in_use[$attribute][] = $field_name;
      }
    }

    return $attribute_in_use;
  }

}
