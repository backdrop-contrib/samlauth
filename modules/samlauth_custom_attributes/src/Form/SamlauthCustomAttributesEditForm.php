<?php

namespace Drupal\samlauth_custom_attributes\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for adding a mapped SAML attribute -> user field.
 */
class SamlauthCustomAttributesEditForm extends FormBase {

  /**
   * The set of 'core' entity fields that are mappable.
   *
   * (Name and e-mail are too, but not from this form.)
   */
  const MAPPABLE_CORE_FIELDS = ['langcode'];

  /**
   * A configuration object containing mapping settings.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $mappingConfig;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * SamlauthCustomAttributesEditForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityFieldManagerInterface $entity_field_manager) {
    $this->mappingConfig = $config_factory->getEditable('samlauth_custom_attributes.mappings');
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'samlauth_custom_attributes_edit_form';
  }

  /**
   * Form for adding or editing a mapping.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param int $mapping_id
   *   (optional) The numeric ID of the mapping.
   *
   * @return array
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $mapping_id = NULL) {
    $user_fields = $this->entityFieldManager->getFieldDefinitions('user', 'user');
    $saml_attribute = '';
    $field_name = '';
    if ($mapping_id !== NULL) {
      $mappings = $this->mappingConfig->get('field_mappings');
      $saml_attribute = $mappings[$mapping_id]['attribute_name'];
      $field_name = $mappings[$mapping_id]['field_name'];
      if (!isset($user_fields[$field_name])) {
        $this->messenger()->addError('Currently mapped user field %name is unknown. Saving this form will change the mepping.', ['%name' => $field_name]);
        $field_name = '';
      }
    }

    $form['attribute_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('SAML Attribute'),
      '#description' => $this->t('The name of the SAML attribute you want to sync to the user profile.'),
      '#required' => TRUE,
      '#default_value' => $saml_attribute,
    ];

    $options = ['' => $this->t('- Select -')];
    foreach ($user_fields as $name => $field) {
      if (substr($name, 0, 6) === 'field_' || in_array($name, static::MAPPABLE_CORE_FIELDS, TRUE)) {
        $options[$name] = $field->getLabel();
      }
    }
    $form['field_name'] = [
      '#type' => 'select',
      '#title' => $this->t('User Field'),
      '#description' => $this->t('The user field you want to sync this attribute to.'),
      '#required' => TRUE,
      '#options' => $options,
      '#default_value' => $field_name,
    ];

    // Add this value so we know if it's an add or an edit.
    $form['mapping_id'] = [
      '#type' => 'hidden',
      '#value' => $mapping_id,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => t('Submit'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $mappings = $this->mappingConfig->get('field_mappings');

    // If this is a new mapping, check to make sure the same one isn't already
    // defined.
    if ($mappings && !$form_state->getValue('mapping_id')) {
      foreach ($mappings as $mapping) {
        if ($mapping['attribute_name'] === $form_state->getValue('attribute_name') && $mapping['field_name'] === $form_state->getValue('field_name')) {
          $form_state->setErrorByName('field_name', $this->t('This SAML attribute has already been mapped to this field.'));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $mappings = $this->mappingConfig->get('field_mappings');

    // Set up the new mapping to add to the array.
    $mapping = [
      'attribute_name' => $form_state->getValue('attribute_name'),
      'field_name' => $form_state->getValue('field_name'),
    ];

    // If we're editing, update the value, if we're adding, add it.
    $mapping_id = $form_state->getValue('mapping_id');
    if (is_numeric($mapping_id)) {
      $mappings[$mapping_id] = $mapping;
    }
    else {
      $mappings[] = $mapping;
    }

    // Save the config with the new mappings.
    $this->mappingConfig->set('field_mappings', $mappings)->save();

    // Go back to the listing page.
    $form_state->setRedirect('samlauth_custom_attributes.list');
  }

}
