<?php

namespace Drupal\samlauth_user_fields\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Handles the routes for our mapper.
 */
class SamlauthUserFieldsController extends ControllerBase {

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
   * SamlauthUserFieldsController constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityFieldManagerInterface $entity_field_manager) {
    $this->mappingConfig = $config_factory->get('samlauth_user_fields.mappings');
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
    $output['table'] = [
      '#theme' => 'table',
      '#header' => [
        $this->t('SAML Attribute'),
        $this->t('User Field'),
        $this->t('Use for linking'),
        $this->t('Operations'),
      ],
      '#sticky' => TRUE,
      '#empty' => t("There are no mappings. You can add one using the link above."),
    ];

    $mappings = $this->mappingConfig->get('field_mappings');
    if ($mappings) {
      $fields = $this->entityFieldManager->getFieldDefinitions('user', 'user');
      // We're identifying individual mappings by their numeric indexes in the
      // configuration value (which is defined as a 'sequence' in the config
      // schema). These are not renumbered while saving a mapping, so the
      // danger of using them is acceptable. (URLs would only pointing to a
      // different mapping if we delete the highest numbered mapping and re-add
      // one. Maybe things are renumbered arter exporting configuration, I
      // haven't tested, but that's also an acceptable risk.)
      foreach ($mappings as $id => $mapping) {
        $operations = [
          '#type' => 'dropbutton',
          '#links' => [
            'edit' => [
              'title' => $this->t('edit'),
              'url' => Url::fromRoute('samlauth_user_fields.edit', ['mapping_id' => $id]),
            ],
            'delete' => [
              'title' => $this->t('delete'),
              'url' => Url::fromRoute('samlauth_user_fields.delete', ['mapping_id' => $id]),
            ],
          ],
        ];

        $user_field = isset($fields[$mapping['field_name']])
          ? $fields[$mapping['field_name']]->getLabel() : $this->t('Unknown field %name', ['%name' => $mapping['field_name']]);
        $output['table']['#rows'][$id] = [
          'saml_attribute' => $mapping['attribute_name'],
          'user_field' => $user_field,
          'link_user_order' => $mapping['link_user_order'] ?? '',
          'operations' => render($operations),
        ];
      }
    }

    return $output;
  }

}
