<?php
/**
 * Created by IntelliJ IDEA.
 * User: jbaker
 * Date: 7/13/17
 * Time: 4:06 PM
 */

namespace Drupal\samlauth_custom_attributes\Form;


use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for deleting a mapping.
 *
 * Class SamlauthCustomAttributesDeleteForm
 *
 * @package Drupal\samlauth_custom_attributes\Form
 */
class SamlauthCustomAttributesDeleteForm extends ConfirmFormBase {

  /**
   * @var \Drupal\Core\Config\Config
   */
  protected $mappingConfig;

  /**
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The name of the attribute we're deleting (needed for the confirm message).
   *
   * @var string
   */
  protected $attribute_name;

  /**
   * The name of the field we're deleting (needed for the confirm message).
   *
   * @var string
   */
  protected $field_name;

  /**
   * SamlauthCustomAttributesDeleteForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   */
  public function __construct(EntityFieldManagerInterface $entity_field_manager) {
    $configFactory = $this->configFactory();
    $this->mappingConfig = $configFactory->getEditable('samlauth_custom_attributes.mappings');
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *
   * @return static
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_field.manager')
    );
  }

  /**
   * @inheritdoc
   */
  public function getFormId() {
    return 'samlauth_custom_attributes_delete_form';
  }

  /**
   * Form for deleting a mapping.
   *
   * @param $form
   * @param $form_state
   * @param $mapping
   *
   * @return bool|array
   */
  function buildForm(array $form, FormStateInterface $form_state, $mapping = FALSE) {
    if (is_numeric($mapping)) {
      $mappings = $this->mappingConfig->get('mappings');

      // Set these values for the confirm message to pick up on them.
      $this->attribute_name = $mappings[$mapping]['attribute_name'];
      $this->field_name = $mappings[$mapping]['field_name'];

      // Set the mapping id so the submit handler can delete it.
      $form_state->set('samlauth_custom_attributes_mapping', $mapping);

      return parent::buildForm($form, $form_state);
    }
    return FALSE;
  }

  /**
   * @inheritdoc
   */
  public function getQuestion() {
    // Get the pretty label for the message.
    $fields = $this->entityFieldManager->getFieldDefinitions('user', 'user');
    $field_name = $fields[$this->field_name]->getLabel();

    return $this->t('Are you sure you want to delete the "' . $this->attribute_name . ' > ' . $field_name . '" mapping?');
  }

  /**
   * @inheritdoc
   */
  public function getCancelUrl() {
    return new Url('samlauth_custom_attributes.list');
  }

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  function submitForm(array &$form, FormStateInterface $form_state) {
    $mappings = $this->mappingConfig->get('mappings');

    // Remove the mapping from the array.
    unset($mappings[$form_state->get('samlauth_custom_attributes_mapping')]);

    // Save the new config.
    $this->mappingConfig->set('mappings', $mappings)->save();

    // Go back to the list page.
    $form_state->setRedirect('samlauth_custom_attributes.list');
  }
}
