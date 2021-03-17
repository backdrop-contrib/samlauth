<?php

namespace Drupal\samlauth_user_fields\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\samlauth_user_fields\EventSubscriber\UserFieldsEventSubscriber;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for adding a mapped SAML attribute -> user field.
 */
class SamlauthMappingEditForm extends FormBase {

  /**
   * Field types that can be mapped.
   */
  const MAP_FIELD_TYPES = [
    'boolean',
    'email',
    'float',
    'integer',
    'language',
    'link',
    'list_float',
    'list_integer',
    'list_string',
    'string',
    'string_long',
    'telephone',
    'timestamp',
  ];

  /**
   * User fields (of mappable types) that should not be mappable.
   */
  const PREVENT_MAP_FIELDS = [
    // Name and email are mappable, but not from this form.
    'name',
    'mail',
    'uid',
    'status',
    'access',
    'login',
    'init',
    // preferred(_admin)_langcode is mappable. (default_)langcode seem to be
    // standard fields on entities.
    'langcode',
    'default_langcode',
  ];

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * SamlauthMappingEditForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   */
  public function __construct(EntityFieldManagerInterface $entity_field_manager) {
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'samlauth_user_fields_edit_form';
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
    $mappings = $this->configFactory()->get(UserFieldsEventSubscriber::CONFIG_OBJECT_NAME)->get('field_mappings');

    // @todo make code that captures all attributes from a SAML authentication
    //   message (only if enabled here via a special temporary option) and
    //   fills a list of possible attribute names. If said list is populated,
    //   we can present a select element in the add/edit screen - though we
    //   always want to keep the option for the user of entering an attribute
    //   name manually, so this will complicate the screen a bit.
    $form['attribute_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('SAML Attribute'),
      '#description' => $this->t('The name of the SAML attribute you want to sync to the user profile.'),
      '#required' => TRUE,
      '#default_value' => $mappings[$mapping_id]['attribute_name'] ?? NULL,
    ];

    $options = ['' => $this->t('- Select -')];
    foreach ($user_fields as $name => $field) {
      if (in_array($field->getType(), static::MAP_FIELD_TYPES, TRUE)
          && !in_array($name, static::PREVENT_MAP_FIELDS, TRUE)) {
        $options[$name] = $field->getLabel();
      }
    }
    $field_name = NULL;
    if ($mapping_id !== NULL) {
      $field_name = $mappings[$mapping_id]['field_name'];
      if (!isset($options[$field_name])) {
        $this->messenger()->addError('Currently mapped user field %name is unknown. Saving this form will change the mapping.', ['%name' => $field_name]);
        $field_name = NULL;
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

    // The huge description isn't very good UX, but we'll postpone thinking
    // about it until we integrate this mapping with the mapping for
    // name + email - or until someone else sends in a fix for this.
    $form['link_user_order'] = [
      '#type' => 'number',
      '#size' => 2,
      '#title' => $this->t('Link user?'),
      '#description' => $this->t("Provide a value here if a first login should attempt to match an existing non-linked Drupal user on the basis of this field's value. The exact value only matters when multiple link attempts are defined (to determine order of attempts and/or combination with other fields). See the help text with the list for more info."),
      '#default_value' => $mappings[$mapping_id]['link_user_order'] ?? NULL,
    ];

    // Add this value so we know if it's an add or an edit.
    $form['mapping_id'] = [
      '#type' => 'value',
      '#value' => $mapping_id,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $mappings = $this->configFactory()->get(UserFieldsEventSubscriber::CONFIG_OBJECT_NAME)->get('field_mappings');

    // If this is a new mapping, check to make sure a 'same' one isn't already
    // defined.
    $our_mapping_id = $form_state->getValue('mapping_id');
    $our_match_id = $form_state->getValue('link_user_order');
    foreach ($mappings as $mapping_id => $mapping) {
      if ($mapping_id != $our_mapping_id || $our_mapping_id === '') {
        if ($our_match_id !== '' && isset($mapping['link_user_order']) && $our_match_id == $mapping['link_user_order']
            && $mapping['field_name'] === $form_state->getValue('field_name')) {
          $form_state->setErrorByName('field_name', $this->t("This user field is already used for the same 'Link' value."));
        }
        // Allow mappings from/to the same attribute/field if both are used in
        // a different match/link expression. It's far fetched, but the
        // duplicate doesn't make a difference for the mapping in practice.
        if (($our_match_id === '' || !isset($mapping['link_user_order']) || $our_match_id == $mapping['link_user_order'])
            && $mapping['field_name'] === $form_state->getValue('field_name')
            && $mapping['attribute_name'] === $form_state->getValue('attribute_name')) {
          $form_state->setErrorByName('field_name', $this->t('This SAML attribute has already been mapped to this field.'));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory()->getEditable(UserFieldsEventSubscriber::CONFIG_OBJECT_NAME);
    $mappings = $config->get('field_mappings');

    $new_mapping = [
      'attribute_name' => $form_state->getValue('attribute_name'),
      'field_name' => $form_state->getValue('field_name'),
      'link_user_order' => $form_state->getValue('link_user_order'),
    ];

    $mapping_id = $form_state->getValue('mapping_id');
    if (!is_array($mappings)) {
      $mappings = [];
    }
    if ($mapping_id !== NULL) {
      $mappings[$mapping_id] = $new_mapping;
    }
    else {
      $mappings[] = $new_mapping;
    }
    $config->set('field_mappings', $mappings)->save();

    $form_state->setRedirect('samlauth_user_fields.list');
  }

}
