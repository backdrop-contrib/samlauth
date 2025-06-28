<?php

declare(strict_types=1);

namespace Drupal\samlauth\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\samlauth\Entity\IdentityProvider;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * Saml auth Identity Provider form.
 */
final class IdentityProviderForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {

    $form = parent::form($form, $form_state);

    $entity = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $entity->label(),
      '#required' => TRUE,
      '#disabled' => !$entity->isNew(),
      '#description' => $this->t('The unique label of this IdP.'),
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#machine_name' => [
        'exists' => [IdentityProvider::class, 'load'],
      ],
      '#disabled' => !$entity->isNew(),
    ];

    $form['entity_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Entity ID'),
      '#description' => $this->t('The identifier for the IdP.'),
      '#default_value' => $entity->getEntityID(),
    ];

    $form['idp_single_sign_on_service'] = [
      '#type' => 'url',
      '#title' => $this->t('Single Sign-On Service'),
      '#description' => $this->t('URL where the SP will direct authentication requests.'),
      '#default_value' => $entity->getSSOService(),
    ];

    $form['idp_single_logout_service'] = [
      '#type' => 'url',
      '#title' => $this->t('Single Logout Service'),
      '#description' => $this->t('URL where the SP will direct logout requests.'),
      '#default_value' => $entity->getSLOService(),
    ];

    $form['key_storage'] = [
      '#type' => 'details',
      '#title' => $this->t('Key Storage'),
      '#open' => TRUE,
    ];

    $form['key_storage']['public_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Public Key'),
      '#description' => $this->t('The location of the public X.509 certificate file on the filesystem. This location should be accessible by PHP.'),
      '#default_value' => $entity->getPublicKey(),
    ];

    $form['key_storage']['encryption_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Encryption Key'),
      '#description' => $this->t('The location of the optional X.509 certificate file on the filesystem. This file is used for encryption. If this is left empty, the above public key wil be used for encryption. This location should be accessible by PHP.'),
      '#default_value' => $entity->getEncryptionKey(),
    ];

    $form['saml_construction'] = [
      '#type' => 'details',
      '#title' => $this->t('SAML Construction'),
      '#open' => TRUE,
    ];

    $form['saml_construction']['sign_authentication_requests'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sign authentication requests'),
      '#description' => $this->t('Requests sent to the Single Sign-On Service of the IdP will include a signature.*'),
      '#default_value' => $entity->getSignAuthenticationRequests(),
    ];

    $form['saml_construction']['sign_logout_requests'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sign logout requests'),
      '#description' => $this->t('Requests sent to the Single Logout Service of the IdP will include a signature.'),
      '#default_value' => $entity->getSignLogoutRequests(),
    ];

    $form['saml_construction']['sign_logout_responses'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sign logout responses'),
      '#description' => $this->t('Requests sent back to the IdP will include a signature.'),
      '#default_value' => $entity->getSignLogoutResponses(),
    ];

    $form['saml_construction']['signature_algorithm'] = [
      '#type' => 'select',
      '#title' => $this->t('Signature algorithm'),
      '#options' => [
        '' => $this->t('library default'),
        XMLSecurityKey::RSA_SHA1 => 'RSA-SHA1',
        XMLSecurityKey::HMAC_SHA1 => 'HMAC-SHA1',
        XMLSecurityKey::RSA_SHA256 => 'SHA256',
        XMLSecurityKey::RSA_SHA384 => 'SHA384',
        XMLSecurityKey::RSA_SHA512 => 'SHA512',
      ],
      '#states' => [
        'disabled' => [
          ':input[name="sign_authentication_requests"]' => ['checked' => FALSE],
          ':input[name="sign_logout_requests"]' => ['checked' => FALSE],
          ':input[name="sign_logout_responses"]' => ['checked' => FALSE],
        ],
      ],
      '#default_value' => $entity->getSignatureAlgorithm(),
    ];

    $form['saml_construction']['nameid_encrypt'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Encrypt NameID in logout requests'),
      '#description' => $this->t('The NameID included in requests sent to the Single Logout Service of the IdP is encrypted.'),
      '#default_value' => $entity->getNameIDEncrypt(),
    ];

    $form['saml_construction']['encryption_algorithm'] = [
      '#type' => 'select',
      '#title' => $this->t('Encryption algorithm'),
      '#options' => [
        '' => $this->t('library default'),
        XMLSecurityKey::AES128_CBC => 'AES128/CBC',
        XMLSecurityKey::AES192_CBC => 'AES192/CBC',
        XMLSecurityKey::AES256_CBC => 'AES256/CBC',
      ],
      '#description' => $this->t('Algorithm used by the encryption process.'),
      '#states' => [
        'disabled' => [
          ':input[name="nameid_encrypt"]' => ['checked' => FALSE],
        ],
      ],
      '#default_value' => $entity->getEncryptionAlgorithm(),
    ];

    $form['saml_construction']['specify_authentication_context'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Specify authentication context (as "password")'),
      '#description' => $this->t('Specify that only a subset of authentication methods available at the IdP should be used. (When enabled, the "PasswordProtectedTransport" authentication method is specified, which is default behavior for the SAML Toolkit library. If needed, this module should be extended to be able to specify more methods.)'),
      '#default_value' => $entity->getSpecifyAuthenticationContext(),
    ];

    $form['saml_construction']['specify_nameid_policy'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Specify NameID policy'),
      '#description' => $this->t('A NameIDPolicy element is added in authentication requests, mentioning the below format (if "Require NameID to be encrypted" is off).'),
      '#default_value' => $entity->getSpecifyNameIDPolicy(),
    ];

    $form['saml_construction']['name_id'] = [
      '#type' => 'select',
      '#title' => $this->t('NameID format'),
      '#description' => $this->t('The format for the NameID attribute to request from the IdP / to send in logout requests.*'),
      '#options' => [
        '' => $this->t('- Select -'),
        'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified' => $this->t('Unspecified'),
        'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent' => $this->t('Persistent'),
        'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress' => $this->t('Email address'),
        'urn:oasis:names:tc:SAML:2.0:nameid-format:entity' => $this->t('Entity'),
        'urn:oasis:names:tc:SAML:1.1:nameid-format:WindowsDomainQualifiedName' => $this->t('Windows domain qualified name'),
        'urn:oasis:names:tc:SAML:1.1:nameid-format:X509SubjectName' => $this->t('X.509 subject name'),
        'urn:oasis:names:tc:SAML:2.0:nameid-format:transient' => $this->t('Transient'),
        'urn:oasis:names:tc:SAML:2.0:nameid-format:encrypted' => $this->t('Encrypted'),
        'urn:oasis:names:tc:SAML:2.0:nameid-format:kerberos' => $this->t('Kerberos'),
        '*' => $this->t('- Other -'),
      ],
      '#default_value' => $entity->getNameID(),
    ];

    $form['saml_construction']['description'] = [
      '#type' => 'markup',
      '#markup' => '*: ' . $this->t('These options also influence the SP metadata. (They are mentioned as an attribute or child element of the SPSSODescriptor element.)'),
    ];

    $form['saml_validation'] = [
      '#type' => 'details',
      '#title' => $this->t('SAML Message Validation'),
      '#open' => TRUE,
    ];

    $form['saml_validation']['require_nameid'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require NameID'),
      '#description' => $this->t('The authentication response from the IdP must contain a NameID attribute.'),
      '#default_value' => $entity->getRequireNameID(),
    ];

    $form['saml_validation']['allow_duplicates'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow duplicate attribute names'),
      '#description' => $this->t('Do not raise an error when the authentication response contains duplicate attribute elements with the same name.'),
      '#default_value' => $entity->getAllowDuplicates(),
    ];

    $form['saml_validation']['retrieve_signature'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Retrieve logout signature parameters from \$_SERVER['REQUEST']"),
      '#description' => $this->t('Validation of logout requests/responses can fail on some IdPs (among others, ADFS) if this option is not set. This happens independently of the "Strict validation" option.'),
      '#default_value' => $entity->getRetrieveSignature(),
    ];

    $form['saml_validation']['strict_validation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Strict validation of responses"),
      '#description' => $this->t('Validation failures (partly based on the next options) will cause the SAML conversation to be terminated. In production environments, this must be set.'),
      '#default_value' => $entity->getStrictValidation(),
    ];

    $form['saml_validation']['require_signed_messages'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Require messages to be signed"),
      '#description' => $this->t('Responses (and logout requests) from the IdP are expected to be signed.'),
      '#default_value' => $entity->getRequireSignedMessages(),
    ];

    $form['saml_validation']['require_signed_assertions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Require assertions to be signed"),
      '#description' => $this->t('Assertion elements in authentication responses from the IdP are expected to be signed.*'),
      '#default_value' => $entity->getRequireSignedAssertions(),
    ];

    $form['saml_validation']['require_encrypted_assertions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Require assertions to be encrypted"),
      '#description' => $this->t('Assertion elements in responses from the IdP are expected to be encrypted.*'),
      '#default_value' => $entity->getRequireEncryptedAssertions(),
    ];

    $form['saml_validation']['require_encrypted_nameid'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Require NameID to be encrypted"),
      '#description' => $this->t("The NameID in login responses from the IdP is expected to be encrypted. This overrides the requested NameID Format and sets \"Encrypted\" in authentication requests' NameIDPolicy element.*"),
      '#default_value' => $entity->getRequireEncryptedNameID(),
    ];

    $form['saml_validation']['description'] = [
      '#type' => 'markup',
      '#markup' => '*: ' . $this->t('These checks are not done when strict validation is turned off, but the settings also influence the SP metadata. (The "signed" value is mentioned as an attribute of the SPSSODescriptor element. The "encrypted" options add an extra "encryption" certificate descriptor element when enabled.)'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Add IdP'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $message_args = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus(
      match($result) {
        \SAVED_NEW => $this->t('Created new example %label.', $message_args),
        \SAVED_UPDATED => $this->t('Updated example %label.', $message_args),
      }
    );
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

}
