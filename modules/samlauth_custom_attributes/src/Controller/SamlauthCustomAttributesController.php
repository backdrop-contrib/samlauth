<?php

namespace Drupal\samlauth_custom_attributes\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Handles the routes for our mapper.
 */
class SamlauthCustomAttributesController extends ControllerBase {

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
   * SamlauthCustomAttributesController constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityFieldManagerInterface $entity_field_manager) {
    $this->mappingConfig = $config_factory->get('samlauth_custom_attributes.mappings');
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
   * Creates the mapping list page.
   *
   * @return array
   *   Renderable content array.
   */
  public function ssoMappings() {
    // Load the mappings.
    $mappings = $this->mappingConfig->get('field_mappings');
    // Load the user fields.
    $fields = $this->entityFieldManager->getFieldDefinitions('user', 'user');

    // @todo make code that captures all attributes from a SAML authentication
    //   message (only if enabled here via a special temporary option) and
    //   fills a list of possible attribute names. If said list is populated,
    //   we can present a select element in the add/edit screen - though we
    //   always want to keep the option for the user of entering an attribute
    //   name manually, so this will complicate that add/edit screen a bit.
    $output['message'] = [
      '#type' => 'markup',
      '#markup' => '<em>' . $this->t('At this moment, you need to know all SAML attribute names in order to be able to input them. This is possible, among others, by inspecting the SAML messages logged in the "Recent log messages", after enabling "Log incoming SAML messages".') . '</em>',
    ];

    // Set up the table.
    $output['table'] = [
      '#theme' => 'table',
      '#header' => [
        $this->t('SAML Attribute'),
        $this->t('User Field'),
        $this->t('Operations'),
      ],
      '#sticky' => TRUE,
      '#empty' => t("There are no mappings. You can add one using the link above."),
    ];

    // If there are mapping, process them.
    if ($mappings) {
      foreach ($mappings as $id => $mapping) {
        // Set up the operations dropbutton.
        $operations = [
          '#type' => 'dropbutton',
          '#links' => [
            'edit' => [
              'title' => $this->t('edit'),
              'url' => Url::fromRoute('samlauth_custom_attributes.edit', ['mapping' => $id]),
            ],
            'delete' => [
              'title' => $this->t('delete'),
              'url' => Url::fromRoute('samlauth_custom_attributes.delete', ['mapping' => $id]),
            ],
          ],
        ];

        // Add the row to the table.
        $user_field = isset($fields[$mapping['field_name']])
          ? $fields[$mapping['field_name']]->getLabel() : $this->t('Unknown field %name', ['%name' => $mapping['field_name']]);
        $output['table']['#rows'][$id] = [
          'saml_attribute' => $mapping['attribute_name'],
          'user_field' => $user_field,
          'operations' => render($operations),
        ];
      }
    }

    return $output;
  }

}
