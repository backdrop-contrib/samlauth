<?php

declare(strict_types=1);

namespace Drupal\samlauth\Entity;

use Drupal\samlauth\IdentityProviderInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the identity provider entity type.
 *
 * @ConfigEntityType(
 *   id = "idp",
 *   label = @Translation("Identity Provider"),
 *   label_collection = @Translation("Identity Providers"),
 *   label_singular = @Translation("Identity Provider"),
 *   label_plural = @Translation("Identity providers"),
 *   label_count = @PluralTranslation(
 *     singular = "@count identity provider",
 *     plural = "@count identity providers",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\samlauth\IdentityProviderListBuilder",
 *     "form" = {
 *       "add" = "Drupal\samlauth\Form\IdentityProviderForm",
 *       "edit" = "Drupal\samlauth\Form\IdentityProviderForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *   },
 *   config_prefix = "idp",
 *   admin_permission = "administer identity providers",
 *   links = {
 *     "collection" = "/admin/structure/idp",
 *     "add-form" = "/admin/structure/idp/add",
 *     "edit-form" = "/admin/structure/idp/{idp}",
 *     "delete-form" = "/admin/structure/idp/{idp}/delete",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "entity_id",
 *     "idp_single_sign_on_service",
 *     "idp_single_logout_service",
 *     "public_key",
 *     "encryption_key",
 *     "sign_authentication_requests",
 *     "sign_logout_requests",
 *     "sign_logout_responses",
 *     "signature_algorithm",
 *     "nameid_encrypt",
 *     "encryption_algorithm",
 *     "specify_authentication_context",
 *     "specify_nameid_policy",
 *     "name_id",
 *     "require_nameid",
 *     "allow_duplicates",
 *     "retrieve_signature",
 *     "strict_validation",
 *     "require_signed_messages",
 *     "require_signed_assertions",
 *     "require_encrypted_assertions",
 *     "require_encrypted_nameid",
 *   },
 * )
 */
final class IdentityProvider extends ConfigEntityBase implements IdentityProviderInterface {

  /**
   * The label for the IdP.
   *
   * @var string
   */
  protected ?string $label = NULL;

  /**
   * The ID of the IdP.
   *
   * @var string
   */
  protected string $id;

  /**
   * The Entity ID of the IdP.
   *
   * @var string
   */
  protected ?string $entity_id = NULL;

  /**
   * The URL of the single sign on service.
   *
   * @var string
   */
  protected ?string $idp_single_sign_on_service = NULL;

  /**
   * The URL of the single logout service.
   *
   * @var string
   */
  protected ?string $idp_single_logout_service = NULL;

  /**
   * The file location of the x.509 public key.
   *
   * @var string
   */
  protected ?string $public_key = NULL;

  /**
   * The file location of the x.509 public key used for encryption.
   *
   * @var string
   */
  protected ?string $encryption_key = NULL;

  /**
   * Whether the authentication requests should be signed.
   *
   * @var bool
   */
  protected ?bool $sign_authentication_requests = NULL;

  /**
   * Whether the logout requests should be signed.
   *
   * @var bool
   */
  protected ?bool $sign_logout_requests = NULL;

  /**
   * Whether the logout responses should be signed.
   *
   * @var bool
   */
  protected ?bool $sign_logout_responses = NULL;

  /**
   * The signature algorithm to use.
   * @var string
   */
  protected ?string $signature_algorithm = NULL;

  /**
   * Whether the NameID should be encrypted.
   *
   * @var bool
   */
  protected ?bool $nameid_encrypt = NULL;

  /**
   * The encryption algorithm to use.
   *
   * @var string
   */
  protected ?string $encryption_algorithm = NULL;

  /**
   * Whether to specify the authentication context (as password)
   *
   * @var bool
   */
  protected ?bool $specify_authentication_context = NULL;

  /**
   * Whether to specify the NameID policy.
   *
   * @var bool
   */
  protected ?bool $specify_nameid_policy = NULL;

  /**
   * The NameID attribute to use.
   *
   * @var string
   */
  protected ?string $name_id = NULL;

  /**
   * Whether to require the NameID.
   *
   * @var bool
   */
  protected ?bool $require_nameid = NULL;

  /**
   * Whether to allow duplicate attributes.
   *
   * @var bool
   */
  protected ?bool $allow_duplicates = NULL;

  /**
   * Whether to retrieve the signature from the response.
   *
   * @var bool
   */
  protected ?bool $retrieve_signature = NULL;

  /**
   * Whether to require strict validation.
   *
   * @var bool
   */
  protected ?bool $strict_validation = NULL;

  /**
   * Whether to require messages to be signed.
   *
   * @var bool
   */
  protected ?bool $require_signed_messages = NULL;

  /**
   * Whether to require the assertions to be signed.
   *
   * @var bool
   */
  protected ?bool $require_signed_assertions = NULL;

  /**
   * Whether the assertions should be encrypted.
   *
   * @var bool
   */
  protected ?bool $require_encrypted_assertions = NULL;

  /**
   * Whether to require the responses to be signed.
   *
   * @var bool
   */
  protected ?bool $require_encrypted_nameid = NULL;

  public function getEntityID() {
    return $this->entity_id;
  }

  public function getSSOService() {
    return $this->idp_single_sign_on_service;
  }

  public function getSLOService() {
    return $this->idp_single_logout_service;
  }

  public function getPublicKey() {
    return $this->public_key;
  }

  public function getEncryptionKey() {
    return $this->encryption_key;
  }

  public function getSignatureAlgorithm() {
    return $this->signature_algorithm;
  }

  public function getNameID() {
    return $this->nameid_encrypt;
  }

  public function getEncryptionAlgorithm() {
    return $this->encryption_algorithm;
  }

  public function getSpecifyNameID() {
    return $this->specify_nameid_policy;
  }

  public function getRequireNameID() {
    return $this->require_nameid;
  }

  public function getAllowDuplicates() {
    return $this->allow_duplicates;
  }

  public function getRetrieveSignature() {
    return $this->retrieve_signature;
  }

  public function getRequireSignedMessages() {
    return $this->require_signed_messages;
  }

  public function getRequireSignedAssertions() {
    return $this->require_signed_assertions;
  }

  public function getRequireEncryptedAssertions() {
    return $this->require_encrypted_assertions;
  }

  public function getSignAuthenticationRequests() {
    return $this->sign_authentication_requests;
  }

  public function getSignLogoutRequests() {
    return $this->sign_logout_requests;
  }

  public function getSignLogoutResponses() {
    return $this->sign_logout_responses;
  }

  public function getNameIDEncrypt() {
    return $this->nameid_encrypt;
  }

  public function getRequireEncryptedNameID() {
    return $this->require_encrypted_nameid;
  }

  public function getSpecifyAuthenticationContext() {
    return $this->specify_authentication_context;
  }

  public function getSpecifyNameIdPolicy() {
    return $this->specify_nameid_policy;
  }

  public function getStrictValidation() {
    return $this->strict_validation;
  }

  public function label() {
    return $this->label;
  }
}
