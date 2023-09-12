<?php

namespace Drupal\samlauth\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use OneLogin\Saml2\Utils as SamlUtils;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Identity Provider form.
 *
 * @property \Drupal\samlauth\SamlauthIdpInterface $entity
 */
class SamlauthIdpForm extends EntityForm {

  /**
   * The Key repository service.
   *
   * This is used as an indicator whether we can show a 'Key' selector on
   * screen. This is when the key module is installed - not when the
   * key_asymmetric module is installed. (The latter is necessary for entering
   * public/private keys but reading them will work fine without it, it seems.)
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * Constructs a \Drupal\samlauth\Form\SamlauthConfigureForm object.
   *
   * @param \Drupal\key\KeyRepositoryInterface|null $key_repository
   *   The token service.
   */
  public function __construct($key_repository) {
    $this->keyRepository = $key_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('key.repository', ContainerInterface::NULL_ON_INVALID_REFERENCE)
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {

    $form = parent::form($form, $form_state);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->label(),
      '#description' => $this->t('Label for the identity provider.'),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $this->entity->id(),
      '#machine_name' => [
        'exists' => '\Drupal\samlauth\Entity\SamlauthIdp::load',
      ],
      '#disabled' => !$this->entity->isNew(),
    ];

    // Create options for cert/key type select element, and list of Keys for
    // 'key' select element.
    $key_cert_type_options = [
      'key_key' => $this->t('Key storage'),
      'file_file' => $this->t('File'),
      'config_config' => $this->t('Configuration'),
      'key_file' => $this->t('Key/File'),
      'key_config' => $this->t('Key/Config'),
      'file_config' => $this->t('File/Config'),
    ];

    // List of certs, for selection in IdP section.
    $selectable_public_certs = [];
    // List of certs referencing a private key, for selection in SP section.
    $selectable_public_keypairs = [];
    $referenced_private_key_ids = [];
    // List of keys that are selectable on their own, for selection in SP
    // section if the cert type is file/config; these are not necessarily
    // referenced from a certificate.
    $selectable_private_keys = [];
    if ($this->keyRepository) {
      $selectable_private_keys = $this->keyRepository->getKeyNamesAsOptions(['type' => 'asymmetric_private']);
      $keys = $this->keyRepository->getKeysByType('asymmetric_public');
      foreach ($keys as $public_key_id => $key) {
        $selectable_public_certs[$public_key_id] = $key->label();
        $key_type = $key->getKeyType();
        assert($key_type instanceof KeyPluginBase);
        $key_type_settings = $key_type->getConfiguration();
        if (!empty($key_type_settings['private_key'])) {
          $selectable_public_keypairs[$public_key_id] = $key->label();
          $referenced_private_key_ids[$public_key_id] = $key_type_settings['private_key'];
        }
      }
    }
    else {
      unset($key_cert_type_options['key_key'], $key_cert_type_options['key_file'], $key_cert_type_options['key_config']);
    }

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $this->entity->status(),
    ];

//    $form['idp_metadata_url'] = [
//      '#type' => 'url',
//      '#title' => $this->t('Metadata URL'),
//      '#description' => $this->t('URL of the XML metadata for the IdP.'),
//      '#default_value' => $this->entity->get('idp_metadata_url'),
//    ];
    $form['idp_entity_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Entity ID'),
      '#description' => $this->t('The identifier representing the IdP.'),
      '#default_value' => $this->entity->get('idp_entity_id'),
    ];

    $form['sp_entity_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('SP Entity ID'),
      '#description' => $this->t('The identifier representing the SP to be sent to the IdP. If not set, defaults to general configuration.'),
      '#default_value' => $this->entity->get('sp_entity_id'),
    ];

    $form['user_info']['unique_id_attribute'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Unique ID attribute'),
      '#description' => $this->t("A SAML attribute whose value is unique per user and does not change over time. Its value is stored by Drupal and linked to the Drupal user that is logged in. (In principle, a non-transient NameID could also be used for this value; the SAML Authentication module does not support this yet.)<br>Example: <em>eduPersonPrincipalName</em> or <em>eduPersonTargetedID</em>"),
      '#default_value' => $this->entity->get('unique_id_attribute') ?: 'eduPersonTargetedID',
    ];

    $form['idp_single_sign_on_service'] = [
      '#type' => 'url',
      '#title' => $this->t('Single Sign On Service'),
      '#description' => $this->t('URL where the SP will direct authentication requests.'),
      '#default_value' => $this->entity->get('idp_single_sign_on_service'),
    ];

    $form['idp_single_log_out_service'] = [
      '#type' => 'url',
      '#title' => $this->t('Single Logout Service'),
      '#description' => $this->t('URL where the SP will direct logout requests.'),
      '#default_value' => $this->entity->get('idp_single_log_out_service'),
    ];

    $form['idp_change_password_service'] = [
      '#type' => 'url',
      '#title' => $this->t('Change Password URL'),
      '#description' => $this->t("URL where users will be directed to change their password. (This is something your IdP might implement but it's outside of the SAML specification. All we do is just redirect /saml/changepw to the configured URL.)"),
      '#default_value' => $this->entity->get('idp_change_password_service'),
    ];

    $certs = $this->entity->get('idp_certs');
    $encryption_cert = $this->entity->get('idp_cert_encryption');
    // @todo remove this block; idp_cert_type was removed in 3.3.
    if (!$certs && !$encryption_cert) {
      $value = $this->entity->get('idp_x509_certificate');
      $certs = $value ? [$value] : [];
      $value = $this->entity->get('idp_x509_certificate_multi');
      if ($value) {
        if ($this->entity->get('idp_cert_type') === 'encryption') {
          $encryption_cert = $value;
        }
        else {
          $certs[] = $value;
        }
      }
    }
    // Check if all certs are of the same type. The SSO part of the module can
    // handle that fine (if someone saved the configuration that way) but the
    // UI cannot; it would make things look more complicated and I don't see a
    // reason to do so.
    $cert_types = $encryption_cert ? strstr($encryption_cert, ':', TRUE) : NULL;
    foreach ($certs as $value) {
      if(!is_array($value)) {

        $cert_type = strstr($value, ':', TRUE);
        if (!$cert_type) {
          $cert_type = 'config';
        }
        if ($cert_types && $cert_types !== $cert_type) {
          if (!$form_state->getUserInput()) {
            $this->messenger()->addWarning($this->t("IdP certificates are not all of the same type. The effect is that the UI probably looks confusing, without much clarity about which entries will get saved. Careful when editing."));
          }
          $cert_types = ':';
          break;
        }
        $cert_types = $cert_type;
      }
    }

    $options = [
      'file' => $this->t('File'),
      'config' => $this->t('Configuration'),
    ];
    if ($this->keyRepository) {
      $options = ['key' => $this->t('Key storage')] + $options;
    }
    if ($cert_types && !isset($options[$cert_types])) {
      $options = ['' => '?'] + $options;
    }
    if (!$cert_types) {
      $cert_types = $this->keyRepository ? 'key' : 'file';
    }
    $form['idp_cert_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type of values to save for the certificate(s)'),
      '#options' => $options,
      '#default_value' => isset($options[$cert_types]) ? $cert_types : '',
    ];

    $form['idp_certs'] = [
      // @todo sometime: 'multivalue'... if #1091852 has been solved for a long
      //   time so we don't need the #description_suffix anymore.
      '#type' => 'samlmultivalue',
      '#add_empty' => FALSE,
      '#title' => $this->t('X.509 Certificate(s)'),
      '#description' => $this->t('Public X.509 certificate(s) of the IdP, used for validating signatures (and by default also for encryption).'),
      '#add_more_label' => $this->t('Add extra certificate'),
    ];
    if ($this->keyRepository) {
      $form['idp_certs']['key'] = [
        '#type' => 'select',
        '#title' => $this->t('Certificate'),
        '#description' => $this->t('Add certificates in the <a href=":url">Keys</a> list.', [
          ':url' => Url::fromRoute('entity.key.collection')->toString(),
        ]),
        '#options' => $selectable_public_certs,
        '#empty_option' => $this->t('- Select a certificate -'),
        '#states' => [
          'visible' => [
            ':input[name="idp_cert_type"]' => [
              ['value' => 'key'],
              'or',
              ['value' => ''],
            ],
          ],
        ],
      ];
    }
    $form['idp_certs'] += [
      'file' => [
        '#type' => 'textfield',
        '#title' => $this->t('Certificate Filename'),
        '#states' => [
          'visible' => [
            ':input[name="idp_cert_type"]' => [
              ['value' => 'file'],
              'or',
              ['value' => ''],
            ],
          ],
        ],
      ],
      'cert' => [
        '#type' => 'textarea',
        '#title' => $this->t('Certificate'),
        '#description' => $this->t("Line breaks and '-----BEGIN/END' lines are optional."),
        '#states' => [
          'visible' => [
            ':input[name="idp_cert_type"]' => [
              ['value' => 'config'],
              'or',
              ['value' => ''],
            ],
          ],
        ],
      ],
      // Bug #1091852 keeps all child elements visible. This JS was an attempt
      // at fixing this but makes them all invisible, which is worse. (Note we
      // cannot just make JS that hides the ones we need to hide, because then
      // they don't respond to #states changes anymore.)
      // '#attached' => ['library' => ['samlauth/fix1091852']],.
    ];
    if ($this->getRequest()->getMethod() === 'POST') {
      // We hacked #description_suffix into MultiValue.
      $form['idp_certs']['#description_suffix'] = $this->t('<div class="messages messages--warning"><strong>Apologies if multiple types of input elements are visible in every row. Please fill only the appropriate type, or re-select the "Type of values" above.</strong></div>');
    }
    if ($certs) {
      $form['idp_certs']['#default_value'] = [];
      foreach ($certs as $index => $value) {
        if(!is_array($value)) {

          $cert_type = strstr($value, ':', TRUE);
          $form['idp_certs']['#default_value'][] =
            in_array($cert_type, ['key', 'file'], TRUE)
              ? [$cert_type => substr($value, strlen($cert_type) + 1)]
              : ['cert' => $this->formatKeyOrCert($value, TRUE)];
          if (!$form_state->getUserInput() && $cert_type === 'file' && !@file_exists(substr($value, 5))) {
            $this->messenger()->addWarning($this->t('IdP certificate file@index is missing or not accessible.', [
              '@index' => $index ? " $index" : '',
            ]));
          }
        }
      }
    }

    $description = $this->t("Optional public X.509 certificate used for encrypting the NameID in logout requests (if specified below). If left empty, the first certificate above is used for encryption too.");
    if ($this->keyRepository) {
      // It is odd to make disabled-ness depend on a security checkbox that is
      // furthe down below, but at least this makes clear that this encryption
      // cert is only used for one very specific thing. Also, it is likely that
      // only very few installations use a separate encryption certificate.
      $form['idp_certkey_encryption'] = [
        '#type' => 'select',
        '#title' => $this->t('Encryption Certificate'),
        '#description' => $description,
        '#default_value' => $cert_types === 'key' && $encryption_cert ? substr($encryption_cert, 4) : '',
        '#options' => $selectable_public_certs,
        '#empty_option' => $this->t('- Select a certificate -'),
        '#states' => [
          'visible' => [
            ':input[name="idp_cert_type"]' => [
              ['value' => 'key'],
              'or',
              ['value' => ''],
            ],
          ],
        ],
      ];
    }
    $form['idp_certfile_encryption'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Encryption Certificate Filename'),
      '#description' => $description,
      '#default_value' => $cert_types === 'file' && $encryption_cert ? substr($encryption_cert, 5) : '',
      '#states' => [
        'visible' => [
          ':input[name="idp_cert_type"]' => [
            ['value' => 'file'],
            'or',
            ['value' => ''],
          ],
        ],
      ],
    ];
    $form['idp_cert_encryption'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Encryption Certificate'),
      '#description' => $description,
      '#default_value' => $cert_types === 'config' && $encryption_cert ? $this->formatKeyOrCert($encryption_cert, TRUE) : '',
      '#states' => [
        'visible' => [
          ':input[name="idp_cert_type"]' => [
            ['value' => 'config'],
            'or',
            ['value' => ''],
          ],
        ],
      ],
    ];
    if (!$form_state->getUserInput() && $cert_types === 'file' && $encryption_cert && !@file_exists(substr($encryption_cert, 5))) {
      $this->messenger()->addWarning($this->t('IdP encryption certificate file is missing or not accessible.'));
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $idp_cert_type = $form_state->getValue('idp_cert_type');
    $idp_certs = $form_state->getValue('idp_certs');
    foreach ($idp_certs as $index => $item) {
      if (!empty($item['file']) && in_array($idp_cert_type, ['', 'file']) && $item['file'][0] !== '/') {
        $form_state->setErrorByName("idp_certs][$index][file", $this->t('IdP certificate filename must be absolute.'));
      }
      if (!$idp_cert_type && ((!empty($item['key']) && !empty($item['file'])) || (!empty($item['key']) && !empty($item['cert'])) || (!empty($item['file']) && !empty($item['cert'])))) {
        $form_state->setErrorByName("idp_certs][$index][cert", $this->t('Only one new certificate (filename) element must be populated per row.'));
      }
    }
    $keyname = $form_state->getValue('idp_certkey_encryption');
    $filename = $form_state->getValue('idp_certfile_encryption');
    $full_cert = $form_state->getValue('idp_cert_encryption');
    if ($filename && in_array($idp_cert_type, ['', 'file']) && $filename[0] !== '/') {
      $form_state->setErrorByName('idp_certfile_encryption', $this->t('IdP encryption certificate filename must be absolute.'));
    }
    if (!$idp_cert_type && (($keyname && $filename) || ($keyname && $full_cert) || ($filename && $full_cert))) {
      $form_state->setErrorByName("idp_cert_encryption", $this->t('IdP certificate and filename cannot both be set.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $idp_cert_type = $form_state->getValue('idp_cert_type');
    $idp_certs = [];
    foreach ($form_state->getValue('idp_certs') as $item) {
      // We validated that max. 1 of the values is set if $idp_cert_type == ''.
      if (!empty($item['key']) && in_array($idp_cert_type, ['', 'key'])) {
        $idp_certs[] = "key:{$item['key']}";
      }
      if (!empty($item['file']) && in_array($idp_cert_type, ['', 'file'])) {
        $idp_certs[] = "file:{$item['file']}";
      }
      if (!empty($item['cert']) && in_array($idp_cert_type, ['', 'config'])) {
        $idp_certs[] = $this->formatKeyOrCert($item['cert'], FALSE);
      }
    }
    $idp_cert_encryption = $form_state->getValue('idp_certkey_encryption');
    if ($idp_cert_encryption && in_array($idp_cert_type, ['', 'key'])) {
      // If 'key', the value was changed to the appropriate one in the
      // validate function (if necessary).
      $idp_cert_encryption = "key:$idp_cert_encryption";
    }
    if (!$idp_cert_encryption && in_array($idp_cert_type, ['', 'file'])) {
      $idp_cert_encryption = $form_state->getValue('idp_certfile_encryption');
      if ($idp_cert_encryption) {
        $idp_cert_encryption = "file:$idp_cert_encryption";
      }
    }
    if (!$idp_cert_encryption && in_array($idp_cert_type, ['', 'config'])) {
      $idp_cert_encryption = $form_state->getValue('idp_cert_encryption');
      if ($idp_cert_encryption) {
        $idp_cert_encryption = $this->formatKeyOrCert($idp_cert_encryption, FALSE);
      }
    }

    $this->entity->set('idp_certs', $idp_certs)
      ->set('idp_cert_encryption', $idp_cert_encryption)
      ->set('sp_cert_folder', NULL);

    // This is never 0 but can be ''. (NULL would mean same as ''.) Unlike
    // others, this value needs to be unset if empty.
    $metadata_valid = $form_state->getValue('metadata_valid_secs');
    if ($metadata_valid) {
      $this->entity->set('metadata_valid_secs', $this->parseReadableDuration($metadata_valid));
    }
    else {
      $this->entity->set('metadata_valid_secs', NULL);
    }

    foreach ([
      'idp_entity_id',
      'sp_entity_id',
      'idp_single_sign_on_service',
      'idp_single_log_out_service',
      'idp_change_password_service',
      'unique_id_attribute',
    ] as $config_value) {
      $this->entity->set($config_value, $form_state->getValue($config_value));
    }

    $result = parent::save($form, $form_state);

    $message_args = ['%label' => $this->entity->label()];
    $message = $result == SAVED_NEW
      ? $this->t('Created new identity provider %label.', $message_args)
      : $this->t('Updated identity provider %label.', $message_args);
    $this->messenger()->addStatus($message);
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

  /**
   * Format a long string in PEM format, or remove PEM format.
   *
   * Our configuration stores unformatted key/cert values, which is what we
   * would get from SAML metadata and what the SAML toolkit expects. But
   * displaying them formatted in a textbox is better for humans, and also
   * allows us to paste PEM-formatted values (as well as unformatted) into the
   * textbox and not have to remove all the newlines manually, if we got them
   * delivered this way.
   *
   * The side effect is that certificates/keys are re- and un-formatted on
   * every save operation, but that should be OK.
   *
   * @param string|null $value
   *   A certificate or private key, either with or without head/footer.
   * @param bool $heads
   *   True to format and include head and footer; False to remove them and
   *   return one string without spaces / line breaks.
   * @param bool $key
   *   (optional) True if this is a private key rather than a certificate.
   *
   * @return string
   *   (Un)formatted key or cert.
   *
   * @todo This probably shouldn't be copy-pasted.
   */
  protected function formatKeyOrCert($value, $heads, $key = FALSE) {
    // If the string contains a colon, it's probably a "key:" config value
    // that we placed in the certificate element because we have no other
    // place for it. Leave it alone (and if it fails validation, so be it).
    if (is_string($value) && strpos($value, ':') === FALSE) {
      $value = $key ?
        SamlUtils::formatPrivateKey($value, $heads) :
        SamlUtils::formatCert($value, $heads);
    }
    return $value;
  }
}
