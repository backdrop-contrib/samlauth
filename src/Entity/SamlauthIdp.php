<?php

namespace Drupal\samlauth\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\samlauth\SamlauthIdpInterface;

/**
 * Defines the identity provider entity type.
 *
 * @ConfigEntityType(
 *   id = "samlauth_idp",
 *   label = @Translation("Identity Provider"),
 *   label_collection = @Translation("Identity Providers"),
 *   label_singular = @Translation("identity provider"),
 *   label_plural = @Translation("identity providers"),
 *   label_count = @PluralTranslation(
 *     singular = "@count identity provider",
 *     plural = "@count identity providers",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\samlauth\SamlauthIdpListBuilder",
 *     "form" = {
 *       "add" = "Drupal\samlauth\Form\SamlauthIdpForm",
 *       "edit" = "Drupal\samlauth\Form\SamlauthIdpForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *     }
 *   },
 *   config_prefix = "samlauth_idp",
 *   admin_permission = "administer samlauth_idp",
 *   links = {
 *     "collection" = "/admin/structure/samlauth-idp",
 *     "add-form" = "/admin/structure/samlauth-idp/add",
 *     "edit-form" = "/admin/structure/samlauth-idp/{samlauth_idp}",
 *     "delete-form" = "/admin/structure/samlauth-idp/{samlauth_idp}/delete"
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "idp_entity_id",
 *     "idp_single_sign_on_service",
 *     "idp_single_log_out_service",
 *     "idp_change_password_service",
 *     "idp_certs",
 *   }
 * )
 */
class SamlauthIdp extends ConfigEntityBase implements SamlauthIdpInterface {

  /**
   * The identity provider ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The identity provider label.
   *
   * @var string
   */
  protected $label;

  /**
   * The identity provider status.
   *
   * @var bool
   */
  protected $status;

  /**
   * The identity provider entity ID.
   *
   * @var string
   */
  protected string $idp_entity_id;

  /**
   * The identity provider single sign on service URL.
   *
   * @var string
   */
  protected string $idp_single_sign_on_service;

  /**
   * The identity provider single log out service URL.
   *
   * @var string
   */
  protected string $idp_single_log_out_service;

  /**
   * The identity provider change password service URL.
   *
   * @var string
   */
  protected string $idp_change_password_service;

  /**
   * An array of certificates for the identity provider.
   *
   * @var string[]
   */
  protected array $idp_certs;

}
