<?php

namespace Drupal\samlauth_user_fields\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for deleting a mapping.
 */
class SamlauthMappingDeleteForm extends ConfirmFormBase {

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
   * The name of the attribute we're deleting (needed for the confirm message).
   *
   * @var string
   */
  protected $attributeName;

  /**
   * The name of the field we're deleting (needed for the confirm message).
   *
   * @var string
   */
  protected $fieldName;

  /**
   * SamlauthMappingDeleteForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityFieldManagerInterface $entity_field_manager) {
    $this->mappingConfig = $config_factory->getEditable('samlauth_user_fields.mappings');
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
    return 'samlauth_user_fields_delete_form';
  }

  /**
   * Form for deleting a mapping.
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
    if ($mapping_id !== NULL) {
      $mappings = $this->mappingConfig->get('field_mappings');

      // Set these values for the confirm message to pick up on them.
      $this->attributeName = $mappings[$mapping_id]['attribute_name'];
      $this->fieldName = $mappings[$mapping_id]['field_name'];

      // Set the mapping id so the submit handler can delete it.
      $form_state->set('mapping_id', $mapping_id);

      return parent::buildForm($form, $form_state);
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $fields = $this->entityFieldManager->getFieldDefinitions('user', 'user');
    $field_name = $fields[$this->fieldName]->getLabel();

    return $this->t('Are you sure you want to delete the mapping for %attribute > %field?', [
      '%attribute' => $this->attributeName,
      '%field' => $field_name,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('samlauth_user_fields.list');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $mappings = $this->mappingConfig->get('field_mappings');

    // Remove the mapping from the array.
    unset($mappings[$form_state->get('mapping_id')]);

    // Save the new config.
    $this->mappingConfig->set('field_mappings', $mappings)->save();

    // Go back to the list page.
    $form_state->setRedirect('samlauth_user_fields.list');
  }

}
