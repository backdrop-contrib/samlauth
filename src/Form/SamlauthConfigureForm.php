<?php

namespace Drupal\samlauth\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Url;
use Drupal\Core\Utility\Token;
use Drupal\samlauth\Controller\SamlController;
use Drupal\user\UserInterface;
use OneLogin\Saml2\Metadata;
use OneLogin\Saml2\Utils as SamlUtils;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a configuration form for samlauth module settings and IdP/SP info.
 */
class SamlauthConfigureForm extends ConfigFormBase {

  /**
   * The EntityTypeManager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The PathValidator service.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected $pathValidator;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The EntityTypeManager service.
   * @param \Drupal\Core\Path\PathValidatorInterface $path_validator
   *   The PathValidator service.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Drupal\key\KeyRepositoryInterface|null $key_repository
   *   The token service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, PathValidatorInterface $path_validator, Token $token, $key_repository) {
    parent::__construct($config_factory);
    $this->entityTypeManager = $entity_type_manager;
    $this->pathValidator = $path_validator;
    $this->token = $token;
    $this->keyRepository = $key_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('path.validator'),
      $container->get('token'),
      $container->get('key.repository', ContainerInterface::NULL_ON_INVALID_REFERENCE)
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    // I'm using ConfigFormBase for the unified save button / message, but
    // don't want to use ConfigFormBase::config(), to keep a unified way of
    // getting config values in forms / not obfuscate call structures and get
    // confused later. So this method/value is unneeded, but ConfigFormBase
    // requires it. Let's make it empty.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'samlauth_configure_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->configFactory()->get(SamlController::CONFIG_OBJECT_NAME);

    $form['saml_login_logout'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Login / Logout'),
    ];

    // Show note for enabling "log in" or "log out" menu link item.
    if (Url::fromRoute('entity.menu.edit_form', ['menu' => 'account'])->access()) {
      $form['saml_login_logout']['#description'] =
        '<em>' . $this->t('Note: You <a href="@url">may want to enable</a> the "log in" / "log out" menu item and disable the original one.', [
          '@url' => Url::fromRoute('entity.menu.edit_form', ['menu' => 'account'])
            ->toString(),
        ]) . '</em>';
    }

    $form['saml_login_logout']['login_menu_item_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Login menu item title'),
      '#description' => $this->t('The title of the SAML login link. Defaults to "Log in".'),
      '#default_value' => $config->get('login_menu_item_title'),
    ];

    $form['saml_login_logout']['logout_menu_item_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Logout menu item title'),
      '#description' => $this->t('The title of the SAML logout link. Defaults to "Log out".'),
      '#default_value' => $config->get('logout_menu_item_title'),
    ];

    // This is false by default, to maintain parity with core user/reset links.
    $form['saml_login_logout']['logout_different_user'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log out different user upon re-authentication.'),
      '#description' => $this->t('If a login (coming from the IdP) happens while another user is still logged into the site, that user is logged out and the new user is logged in. (By default, the old user stays logged in and a warning is displayed.)'),
      '#default_value' => $config->get('logout_different_user'),
    ];

    /** @var \Drupal\user\Entity\Role[] $roles */
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    unset($roles[UserInterface::ANONYMOUS_ROLE]);
    $role_options = [];
    foreach ($roles as $name => $role) {
      $role_options[$name] = $role->label();
    }
    $form['saml_login_logout']['linking']['drupal_login_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles allowed to use Drupal login also when linked to a SAML login'),
      '#description' => $this->t('Users who have previously logged in through the SAML Identity Provider can only use the standard Drupal login method if they have one of the roles selected here. Drupal users that have never logged in through the IdP are not affected by this restriction.'),
      '#options' => $role_options,
      '#default_value' => $config->get('drupal_login_roles') ?? [],
    ];

    $form['saml_login_logout']['local_login_saml_error'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Tell disallowed users they must log in using SAML.'),
      '#description' => $this->t('If not checked, we show the generic "Unrecognized username or password" message to users who cannot use the standard Drupal login method. This prevents disclosing information about whether the account name exists, but is untrue / potentially confusing.', [
        ':permission' => Url::fromUri('base:admin/people/permissions', ['fragment' => 'module-samlauth'])->toString(),
      ]),
      // TRUE on existing installations where the checkbox didn't exist before;
      // FALSE on new installations.
      '#default_value' => $config->get('local_login_saml_error') ?? TRUE,
    ];

    $form['saml_login_logout']['login_redirect_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Login redirect URL'),
      '#description' => $this->t("The default URL to redirect the user to after login. This should be an internal path starting with a slash, or an absolute URL. Defaults to the logged-in user's account page."),
      '#default_value' => $config->get('login_redirect_url'),
    ];

    $form['saml_login_logout']['logout_redirect_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Logout redirect URL'),
      '#description' => $this->t('The default URL to redirect the user to after logout. This should be an internal path starting with a slash, or an absolute URL. Defaults to the front page.'),
      '#default_value' => $config->get('logout_redirect_url'),
    ];

    $form['saml_login_logout']['error_redirect_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Error redirect URL'),
      '#description' => $this->t("The default URL to redirect the user to after an error occurred. This should be an internal path starting with a slash, or an absolute URL. Defaults to the front page."),
      '#default_value' => $config->get('error_redirect_url'),
      '#states' => [
        'disabled' => [
          ':input[name="error_throw"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['saml_login_logout']['error_throw'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Bypass error handling"),
      '#description' => $this->t("No redirection or meaningful logging is done. This better enables custom code to handle errors."),
      '#default_value' => $config->get('error_throw'),
    ];

    $form['service_provider'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Service Provider'),
      '#description' => $this->t("Metadata is not exposed by default; see <a href=\":permissions\">permissions</a>. It is influenced by this configuration section, as well as by some more advanced SAML message options below. Those options often don't matter for getting SAML login into Drupal to work.", [
        ':permissions' => Url::fromUri('base:admin/people/permissions', ['fragment' => 'module-samlauth'])->toString(),
      ]),
    ];

    $form['service_provider']['config_info'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('Metadata URL: :url', [
          ':url' => Url::fromRoute('samlauth.saml_controller_metadata', [], ['absolute' => TRUE])->toString(),
        ]),
        $this->t('Assertion Consumer Service: :url', [
          ':url' => Url::fromRoute('samlauth.saml_controller_acs', [], ['absolute' => TRUE])->toString(),
        ]),
        $this->t('Single Logout Service: :url', [
          ':url' => Url::fromRoute('samlauth.saml_controller_sls', [], ['absolute' => TRUE])->toString(),
        ]),
      ],
      '#empty' => [],
      '#list_type' => 'ul',
    ];

    $form['service_provider']['sp_entity_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Entity ID'),
      '#description' => $this->t('The identifier representing the SP.'),
      '#default_value' => $config->get('sp_entity_id'),
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
        $key_type_settings = $key->getKeyType()->getConfiguration();
        if (!empty($key_type_settings['private_key'])) {
          $selectable_public_keypairs[$public_key_id] = $key->label();
          $referenced_private_key_ids[$public_key_id] = $key_type_settings['private_key'];
        }
      }
    }
    else {
      unset($key_cert_type_options['key_key'], $key_cert_type_options['file_key'], $key_cert_type_options['config_key']);
    }

    // Get cert + key; see which types they are and do custom checks.
    $sp_private_key = $config->get('sp_private_key');
    $sp_cert = $config->get('sp_x509_certificate');
    $sp_new_cert = $config->get('sp_new_certificate');
    // @todo remove reference to $cert_folder in 4.x.
    $cert_folder = $config->get('sp_cert_folder');
    if ($cert_folder && is_string($cert_folder)) {
      // Update function hasn't run yet.
      $sp_private_key = "file:$cert_folder/certs/sp.key";
      $sp_cert = "file:$cert_folder/certs/sp.crt";
    }
    $sp_key_type = strstr($sp_private_key, ':', TRUE);
    if ($sp_key_type) {
      $sp_private_key = substr($sp_private_key, strlen($sp_key_type) + 1);
      if ($sp_key_type === 'key' && !isset($selectable_private_keys[$sp_private_key])) {
        // Warn if the key doesn't exist. If so, we don't want to mess with the
        // value (unlike when the cert doesn't exist; see below); let's add it
        // to the 'selectable keys' so validation doesn't fail.
        if ($this->keyRepository) {
          if (!$form_state->getUserInput()) {
            $this->messenger()->addWarning($this->t("Key entity '@key_name' for SP private key is missing.", [
              '@key_name' => $sp_private_key,
            ]));
          }
          $selectable_private_keys[$sp_private_key] = $this->t('@value (does not exist)', [
            '@value' => $sp_private_key,
          ]);
        }
        else {
          // ...except if we cannot display 'selectable keys' at all.
          if (!$form_state->getUserInput()) {
            $this->messenger()->addWarning($this->t('Key module is disabled even though the SP private key has Key storage configured.'));
          }
          $sp_private_key = "key:$sp_private_key";
          $sp_key_type = 'config';
        }
      }
    }
    elseif ($sp_private_key) {
      $sp_key_type = 'config';
    }
    $sp_cert_type = strstr($sp_cert, ':', TRUE);
    if ($sp_cert_type) {
      $sp_cert = substr($sp_cert, strlen($sp_cert_type) + 1);
      if ($sp_cert_type === 'key') {
        // Warn if the key doesn't exist; not on validation but on every form
        // display. Display the original value (including "key:") in the
        // 'config' textarea; if we put it in the select element just like the
        // private key, we'd need to alter the validation code too too (to not
        // derive the private key from this nonexistent key in this case). It
        // may now fail validation (unlike with missing files).
        if (!isset($selectable_public_keypairs[$sp_cert])) {
          if (!$form_state->getUserInput()) {
            if ($this->keyRepository) {
              // Text differs from key, b/c reasons can be slightly different.
              $this->messenger()->addWarning($this->t("Key entity '@key_name' for SP certificate is missing, or not referenced from a public certificate.", [
                '@key_name' => $sp_cert,
              ]));
            }
            else {
              $this->messenger()->addWarning($this->t('Key module is disabled even though the SP certificate has Key storage configured.'));
            }
          }
          $sp_cert = "key:$sp_cert";
          $sp_cert_type = 'config';
        }
        elseif ($sp_key_type === 'key' && $referenced_private_key_ids[$sp_cert] !== $sp_private_key) {
          // If our key exists but isn't referenced from our cert, we cannot
          // display both in our regular single 'keypair' selector. Move the
          // cert to the 'config' textarea so we display the private key in its
          // standalone keys select element.
          if (!$form_state->getUserInput()) {
            $this->messenger()->addWarning($this->t("Certificate '@cert_keyname' does not reference key '@key_keyname', which our UI cannot handle. The effect is that the certificate selection UI now probably looks confusing and may fail validation.", [
              '@cert_keyname' => $sp_cert,
              '@key_keyname' => $sp_private_key,
            ]));
          }
          $sp_cert = "key:$sp_cert";
          $sp_cert_type = 'config';
        }
      }
    }
    elseif ($sp_cert) {
      $sp_cert_type = 'config';
    }
    $sp_new_cert_type = strstr($sp_new_cert, ':', TRUE);
    if ($sp_new_cert_type) {
      $sp_new_cert = substr($sp_new_cert, strlen($sp_new_cert_type) + 1);
      if ($sp_new_cert_type === 'key' && !isset($selectable_public_keypairs[$sp_new_cert])) {
        if (!$form_state->getUserInput()) {
          if ($this->keyRepository) {
            $this->messenger()->addWarning($this->t("Key entity '@key_name' for new SP certificate is missing, or not referenced from a public certificate.", [
              '@key_name' => $sp_new_cert,
            ]));
          }
          else {
            $this->messenger()->addWarning($this->t('Key module is disabled even though the new SP certificate has Key storage configured.'));
          }
        }
        $sp_new_cert = "key:$sp_new_cert";
        $sp_new_cert_type = 'config';
      }
    }
    elseif ($sp_new_cert) {
      $sp_new_cert_type = 'config';
    }

    if (!$form_state->getUserInput()) {
      // Warn if the files don't exist; not on validation but on every form
      // display. (They may be missing if we're looking at a copy of the site,
      // and we still want to be able to test other form interactions.)
      if ($sp_key_type === 'file' && !file_exists($sp_private_key)) {
        $this->messenger()->addWarning($this->t('SP private key file is missing.'));
      }
      if ($sp_cert_type === 'file' && !file_exists($sp_cert)) {
        $this->messenger()->addWarning($this->t('SP certificate file is missing.'));
      }
      if ($sp_new_cert_type === 'file' && !file_exists($sp_new_cert)) {
        $this->messenger()->addWarning($this->t('SP new certificate file is missing.'));
      }
    }

    // Set default types if key/certificate values are not present yet.
    if (!$sp_key_type) {
      $sp_key_type = $this->keyRepository ? 'key' : 'file';
    }
    if (!$sp_cert_type) {
      if (!$sp_new_cert_type) {
        $sp_new_cert_type = $sp_key_type;
      }
      $sp_cert_type = $sp_new_cert_type;
    }
    elseif (!$sp_new_cert_type) {
      $sp_new_cert_type = $sp_cert_type;
    }

    // Check if these types make sense and, in case of key_key, the combination
    // of both keys can actually be presented as a keypair.
    $sp_key_cert_type = "{$sp_key_type}_{$sp_cert_type}";
    if ($sp_new_cert_type !== $sp_cert_type || !isset($key_cert_type_options[$sp_key_cert_type])) {
      $sp_key_cert_type = '';
      $key_cert_type_options = ['' => '?'] + $key_cert_type_options;
      if (!$form_state->getUserInput()) {
        $this->messenger()->addWarning($this->t("Encountered an unexpected combination of SP key / certificate types (@value). The effect is that the UI probably looks confusing, without much clarity about which entries will get saved. Careful when editing.", [
          '@value' => "$sp_key_type / $sp_cert_type" . ($sp_new_cert_type ? " / $sp_new_cert_type" : ''),
        ]));
      }
    }

    // We have only a subselection of common/logical types, with 'key type'
    // being as least as safe as 'cert type'. If our actual stored types do not
    // match those OR the stored 'cert' and 'new cert' have different types, we
    // add another option '?' which will not hide any key/cert inputs.
    $form['service_provider']['sp_key_cert_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type of values to save for the key/certificate'),
      '#description' => ($this->keyRepository ? $this->t('Key storage is most versatile.') . ' ' : '')
      . $this->t('File is generally considered more secure than configuration.'),
      '#options' => $key_cert_type_options,
      '#default_value' => $sp_key_cert_type,
    ];

    // @todo Links to pages that decode/show info about the key or cert.
    if ($this->keyRepository) {
      // We've decided on one selector for a keypair instead of separate ones
      // for certs and keys (even though we'll store them separately in
      // config), because forcing the user to create references to their
      // private keys is likely beneficial for longer term maintenance. This
      // means we don't show this selector for sp_key_cert_type "key_key".
      // Still, we set the #default_value also in that case which, while not
      // necessary for saving, can be good for the editing experience.
      $form['service_provider']['sp_key_key'] = [
        '#type' => 'select',
        '#title' => $this->t('Private Key'),
        '#description' => $this->t('Add private keys in the <a href=":url">Keys</a> list.', [
          ':url' => Url::fromRoute('entity.key.collection')->toString(),
        ]),
        '#options' => $selectable_private_keys,
        '#empty_option' => $this->t('- Select a private key -'),
        '#default_value' => $sp_key_type === 'key' ? $sp_private_key : '',
        '#states' => [
          'visible' => [
            ':input[name="sp_key_cert_type"]' => [
              ['value' => 'key_file'],
              'or',
              ['value' => 'key_config'],
              'or',
              ['value' => ''],
            ],
          ],
        ],
      ];
    }
    $form['service_provider']['sp_key_file'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Private Key filename'),
      '#description' => $this->t('Absolute filename.'),
      '#default_value' => $sp_key_type === 'file' ? $sp_private_key : '',
      '#states' => [
        'visible' => [
          ':input[name="sp_key_cert_type"]' => [
            ['value' => 'file_file'],
            'or',
            ['value' => 'file_config'],
            'or',
            ['value' => ''],
          ],
        ],
      ],
    ];
    $form['service_provider']['sp_private_key'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Private Key'),
      '#description' => $this->t("Line breaks and '-----BEGIN/END' lines are optional."),
      '#default_value' => $sp_key_type === 'config' ? $this->formatKeyOrCert($sp_private_key, TRUE, TRUE) : '',
      '#states' => [
        'visible' => [
          ':input[name="sp_key_cert_type"]' => [
            ['value' => 'config_config'],
            'or',
            ['value' => ''],
          ],
        ],
      ],
    ];

    if ($this->keyRepository) {
      $form['service_provider']['sp_cert_key'] = [
        '#type' => 'select',
        '#title' => $this->t('X.509 Certificate with attached private key'),
        '#description' => $this->t("Add private keys and certificates (don't forget to reference the private key) in the <a href=\":url\">Keys</a> list.", [
          ':url' => Url::fromRoute('entity.key.collection')->toString(),
        ]),
        '#options' => $selectable_public_keypairs,
        '#empty_option' => $this->t('- Select a certificate -'),
        '#default_value' => $sp_cert_type === 'key' ? $sp_cert : '',
        '#states' => [
          'visible' => [
            ':input[name="sp_key_cert_type"]' => [
              ['value' => 'key_key'],
              'or',
              ['value' => ''],
            ],
          ],
        ],
      ];
    }
    $form['service_provider']['sp_cert_file'] = [
      '#type' => 'textfield',
      '#title' => $this->t('X.509 Certificate Filename'),
      '#description' => $this->t('Absolute filename.'),
      '#default_value' => $sp_cert_type === 'file' ? $sp_cert : '',
      '#states' => [
        'visible' => [
          ':input[name="sp_key_cert_type"]' => [
            ['value' => 'file_file'],
            'or',
            ['value' => 'key_file'],
            'or',
            ['value' => ''],
          ],
        ],
      ],
    ];
    $form['service_provider']['sp_x509_certificate'] = [
      '#type' => 'textarea',
      '#title' => $this->t('X.509 Certificate'),
      '#description' => $this->t("Line breaks and '-----BEGIN/END' lines are optional."),
      '#default_value' => $sp_cert_type === 'config' ? $this->formatKeyOrCert($sp_cert, TRUE) : '',
      '#states' => [
        'visible' => [
          ':input[name="sp_key_cert_type"]' => [
            ['value' => 'key_config'],
            'or',
            ['value' => 'file_config'],
            'or',
            ['value' => 'config_config'],
            'or',
            ['value' => ''],
          ],
        ],
      ],
    ];

    if ($this->keyRepository) {
      // We've decided on one selector for a keypair instead of separate ones
      // for certs and keys (even though we'll store them separately in
      // config), because forcing the user to create references to their
      // private keys is likely beneficial for longer term maintenance.
      $form['service_provider']['sp_new_cert_key'] = [
        '#type' => 'select',
        '#title' => $this->t('New X.509 Certificate'),
        '#description' => $this->t("This is announced in the metadata, to plan for using it in the future. Add the certificate in the <a href=\":url\">Keys</a> list. It must reference a key (even though that won't be used yet), so this cert/key pair is ready to be moved into production.", [
          ':url' => Url::fromRoute('entity.key.collection')->toString(),
        ]),
        '#options' => $selectable_public_keypairs,
        '#empty_option' => $this->t('- Select a certificate -'),
        '#default_value' => $sp_new_cert_type === 'key' ? $sp_new_cert : '',
        '#states' => [
          'visible' => [
            ':input[name="sp_key_cert_type"]' => [
              ['value' => 'key_key'],
              'or',
              ['value' => ''],
            ],
          ],
        ],
      ];
    }
    $form['service_provider']['sp_new_cert_file'] = [
      '#type' => 'textfield',
      '#title' => $this->t('New X.509 Certificate filename'),
      '#description' => $this->t("This is announced in the metadata, to plan for using it in the future. Absolute filename."),
      '#default_value' => $sp_new_cert_type === 'file' ? $sp_new_cert : '',
      '#states' => [
        'visible' => [
          ':input[name="sp_key_cert_type"]' => [
            ['value' => 'key_file'],
            'or',
            ['value' => 'file_file'],
            'or',
            ['value' => ''],
          ],
        ],
      ],
    ];
    $form['service_provider']['sp_new_cert'] = [
      '#type' => 'textarea',
      '#title' => $this->t('New X.509 Certificate'),
      '#description' => $this->t("This is announced in the metadata, to plan for using it in the future. Line breaks and '-----BEGIN/END' lines are optional."),
      '#default_value' => $sp_new_cert_type === 'config' ? $this->formatKeyOrCert($sp_new_cert, TRUE) : '',
      '#states' => [
        'visible' => [
          ':input[name="sp_key_cert_type"]' => [
            ['value' => 'key_config'],
            'or',
            ['value' => 'file_config'],
            'or',
            ['value' => 'config_config'],
            'or',
            ['value' => ''],
          ],
        ],
      ],
    ];

    $form['service_provider']['caching'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Caching / Validity'),
      '#description' => $this->t('These values are low for newly installed sites, and should be raised when login is working.'),
    ];

    $value = $config->get('metadata_valid_secs');
    $default = $this->makeReadableDuration(Metadata::TIME_VALID);
    $form['service_provider']['caching']['metadata_valid_secs'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Metadata validity'),
      // Default is inside the translatable string; it will almost never change.
      '#description' => $this->t("The maximum amount of time that the metadata (which is often cached by IdPs) should be considered valid, in readable format, e.g. \"1 day 8 hours\". As the XML expresses \"validUntil\" as a specific date, a HTTP cache will contain XML with slowly decreasing validity. The default (when left empty) is $default."),
      '#default_value' => $value ? $this->makeReadableDuration($value) : NULL,
    ];

    $form['service_provider']['caching']['metadata_cache_http'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Cache HTTP responses containing metadata'),
      '#description' => $this->t("This affects just (Drupal's and external) response caches, whereas the above also affects caching by the IdP. Caching is only important if the metadata URL can be reached by anonymous visitors. The Max-Age value is derived from the validity."),
      // TRUE on existing installations where the checkbox didn't exist before;
      // FALSE on new installations.
      '#default_value' => $config->get('metadata_cache_http') ?? TRUE,
    ];

    $form['identity_provider'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Identity Provider'),
    ];

    // @todo Allow a user to automagically populate this by providing a
    //   metadata URL for the IdP. OneLogin's IdPMetadataParser can likely help.
    // $form['identity_provider']['idp_metadata_url'] = [
    // '#type' => 'url',
    // '#title' => $this->t('Metadata URL'),
    // '#description' => $this->t('URL of the XML metadata for the IdP.'),
    // '#default_value' => $config->get('idp_metadata_url'),
    // ];
    $form['identity_provider']['idp_entity_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Entity ID'),
      '#description' => $this->t('The identifier representing the IdP.'),
      '#default_value' => $config->get('idp_entity_id'),
    ];

    $form['identity_provider']['idp_single_sign_on_service'] = [
      '#type' => 'url',
      '#title' => $this->t('Single Sign On Service'),
      '#description' => $this->t('URL where the SP will direct authentication requests.'),
      '#default_value' => $config->get('idp_single_sign_on_service'),
    ];

    $form['identity_provider']['idp_single_log_out_service'] = [
      '#type' => 'url',
      '#title' => $this->t('Single Logout Service'),
      '#description' => $this->t('URL where the SP will direct logout requests.'),
      '#default_value' => $config->get('idp_single_log_out_service'),
    ];

    $form['identity_provider']['idp_change_password_service'] = [
      '#type' => 'url',
      '#title' => $this->t('Change Password URL'),
      '#description' => $this->t("URL where users will be directed to change their password. (This is something your IdP might implement but it's outside of the SAML specification. All we do is just redirect /saml/changepw to the configured URL.)"),
      '#default_value' => $config->get('idp_change_password_service'),
    ];

    $certs = $config->get('idp_certs');
    $encryption_cert = $config->get('idp_cert_encryption');
    // @todo remove this block; idp_cert_type was removed in 3.3.
    if (!$certs && !$encryption_cert) {
      $value = $config->get('idp_x509_certificate');
      $certs = $value ? [$value] : [];
      $value = $config->get('idp_x509_certificate_multi');
      if ($value) {
        if ($config->get('idp_cert_type') === 'encryption') {
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
    $cert_types = strstr($encryption_cert, ':', TRUE);
    foreach ($certs as $value) {
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
    $form['identity_provider']['idp_cert_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type of values to save for the certificate(s)'),
      '#options' => $options,
      '#default_value' => isset($options[$cert_types]) ? $cert_types : '',
    ];

    $form['identity_provider']['idp_certs'] = [
      // @todo sometime: 'multivalue'... if #1091852 has been solved for a long
      //   time so we don't need the #description_suffix anymore.
      '#type' => 'samlmultivalue',
      '#add_empty' => FALSE,
      '#title' => $this->t('X.509 Certificate(s)'),
      '#description' => $this->t('Public X.509 certificate(s) of the IdP, used for validating signatures (and by default also for encryption).'),
      '#add_more_label' => $this->t('Add extra certificate'),
    ];
    if ($this->keyRepository) {
      $form['identity_provider']['idp_certs']['key'] = [
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
    $form['identity_provider']['idp_certs'] += [
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
      $form['identity_provider']['idp_certs']['#description_suffix'] = $this->t('<div class="messages messages--warning"><strong>Apologies if multiple types of input elements are visible in every row. Please fill only the appropriate type, or re-select the "Type of values" above.</strong></div>');
    }
    if ($certs) {
      $form['identity_provider']['idp_certs']['#default_value'] = [];
      foreach ($certs as $index => $value) {
        $cert_type = strstr($value, ':', TRUE);
        $form['identity_provider']['idp_certs']['#default_value'][] =
          in_array($cert_type, ['key', 'file'], TRUE)
            ? [$cert_type => substr($value, strlen($cert_type) + 1)]
            : ['cert' => $this->formatKeyOrCert($value, TRUE)];
        if (!$form_state->getUserInput() && $cert_type === 'file' && !file_exists(substr($value, 5))) {
          $this->messenger()->addWarning($this->t('IdP certificate file@index is missing.', [
            '@index' => $index ? " $index" : '',
          ]));
        }
      }
    }

    // @todo change description; remove "whenever we'll support that", if we
    //   start supporting NameIdEncrypted.
    $description = $this->t("Optional public X.509 certificate used for encrypting the NameID in logout requests (whenever we'll support that). If left empty, the first certificate above is used for encryption too.");
    if ($this->keyRepository) {
      $form['identity_provider']['idp_certkey_encryption'] = [
        '#type' => 'select',
        '#title' => $this->t('Certificate'),
        '#description' => $description,
        '#default_value' => $cert_types === 'key' ? substr($encryption_cert, 4) : '',
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
    $form['identity_provider']['idp_certfile_encryption'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Encryption Certificate Filename'),
      '#description' => $description,
      '#default_value' => $cert_types === 'file' ? substr($encryption_cert, 5) : '',
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
    $form['identity_provider']['idp_cert_encryption'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Encryption Certificate'),
      '#description' => $description,
      '#default_value' => $cert_types === 'config' ? $this->formatKeyOrCert($encryption_cert, TRUE) : '',
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
    if (!$form_state->getUserInput() && $cert_types === 'file' && !file_exists(substr($encryption_cert, 5))) {
      $this->messenger()->addWarning($this->t('IdP encryption certificate file is missing.'));
    }

    $form['user_info'] = [
      '#title' => $this->t('User Info and Syncing'),
      '#type' => 'details',
      '#open' => TRUE,
    ];

    $form['user_info']['unique_id_attribute'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Unique ID attribute'),
      '#description' => $this->t("A SAML attribute whose value is unique per user and does not change over time. Its value is stored by Drupal and linked to the Drupal user that is logged in. (In principle, a non-transient NameID could also be used for this value; the SAML Authentication module does not support this yet.)<br>Example: <em>eduPersonPrincipalName</em> or <em>eduPersonTargetedID</em>"),
      '#default_value' => $config->get('unique_id_attribute') ?: 'eduPersonTargetedID',
    ];

    $form['user_info']['linking'] = [
      '#title' => $this->t('Attempt to link SAML data to existing local users'),
      '#type' => 'details',
      '#open' => TRUE,
      '#description' => t('If enabled, whenever the unique ID in the SAML assertion is not already linked to a Drupal user but the assertion data can be matched with an existing non-linked user, that user will be linked and logged in. Matching is attempted in the order of below enabled checkboxes, until a user is found.')
      . '<br><br><em>' . t('Warning: if the data used for matching can be changed by the IdP user, this has security implications; it enables a user to influence which Drupal user they take over.') . '</em>',
    ];

    $form['user_info']['linking']['map_users'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable custom matching'),
      '#description' => $this->t("Allows user matching by the included 'User Fields Mapping' module as well as any other code (event subscriber) installed for this purpose."),
      '#default_value' => $config->get('map_users'),
    ];

    $form['user_info']['linking']['map_users_name'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable matching on name'),
      '#description' => $this->t("Allows matching an existing local user name with value of the user name attribute."),
      '#default_value' => $config->get('map_users_name'),
    ];

    $form['user_info']['linking']['map_users_mail'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable matching on email'),
      '#description' => $this->t("Allows matching an existing local user email with value of the user email attribute."),
      '#default_value' => $config->get('map_users_mail'),
    ];

    unset($role_options[UserInterface::AUTHENTICATED_ROLE]);
    $form['user_info']['linking']['map_users_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles allowed for linking'),
      '#description' => $this->t('If a matched account has any role that is not explicitly allowed here, linking/login is denied.'),
      '#options' => $role_options,
      '#default_value' => $config->get('map_users_roles') ?? [],
    ];

    $form['user_info']['create_users'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create users from SAML data'),
      '#description' => $this->t('If data in the SAML assertion is not linked to a Drupal user, a new user is created using the name / email attributes from the response.'),
      '#default_value' => $config->get('create_users'),
    ];

    $form['user_info']['sync_name'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Synchronize user name on every login'),
      '#default_value' => $config->get('sync_name'),
      '#description' => $this->t('The name attribute in the SAML assertion will be propagated to the linked Drupal user on every login. (By default, the Drupal user name is not changed after user creation.)'),
    ];

    $form['user_info']['sync_mail'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Synchronize email address on every login'),
      '#default_value' => $config->get('sync_mail'),
      '#description' => $this->t('The email attribute in the SAML assertion will be propagated to the linked Drupal user on every login. (By default, the Drupal user email is not changed after user creation.)'),
    ];

    $form['user_info']['user_name_attribute'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User name attribute'),
      '#description' => $this->t('When users are linked / created, this field specifies which SAML attribute should be used for the Drupal user name.<br />Example: <em>cn</em> or <em>eduPersonPrincipalName</em>'),
      '#default_value' => $config->get('user_name_attribute'),
      '#states' => [
        'invisible' => [
          ':input[name="map_users_name"]' => ['checked' => FALSE],
          ':input[name="create_users"]' => ['checked' => FALSE],
          ':input[name="sync_name"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['user_info']['user_mail_attribute'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User email attribute'),
      '#description' => $this->t('When users are linked / created, this field specifies which SAML attribute should be used for the Drupal email address.<br />Example: <em>mail</em>'),
      '#default_value' => $config->get('user_mail_attribute'),
      '#states' => [
        'invisible' => [
          ':input[name="map_users_mail"]' => ['checked' => FALSE],
          ':input[name="create_users"]' => ['checked' => FALSE],
          ':input[name="sync_mail"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['security'] = [
      '#title' => $this->t('SAML Message Construction'),
      '#type' => 'details',
      '#open' => TRUE,
    ];

    $form['security']['security_authn_requests_sign'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sign authentication requests'),
      '#description' => $this->t('Requests sent to the Single Sign-On Service of the IdP will include a signature.'),
      '#default_value' => $config->get('security_authn_requests_sign'),
    ];

    $form['security']['security_logout_requests_sign'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sign logout requests'),
      '#description' => $this->t('Requests sent to the Single Logout Service of the IdP will include a signature.'),
      '#default_value' => $config->get('security_logout_requests_sign'),
    ];

    $form['security']['security_logout_responses_sign'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sign logout responses'),
      '#description' => $this->t('Responses sent back to the IdP will include a signature.'),
      '#default_value' => $config->get('security_logout_responses_sign'),
    ];

    $form['security']['security_signature_algorithm'] = [
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
      '#description' => $this->t('Algorithm used in the signing process.'),
      '#default_value' => $config->get('security_signature_algorithm'),
      '#states' => [
        'invisible' => [
          ':input[name="security_authn_requests_sign"]' => ['checked' => FALSE],
          ':input[name="security_logout_requests_sign"]' => ['checked' => FALSE],
          ':input[name="security_logout_responses_sign"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['security']['security_request_authn_context'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Specify authentication context'),
      '#description' => $this->t('Specify that only a subset of authentication methods available at the IdP should be used. (If checked, the "PasswordProtectedTransport" authentication method is specified, which is default behavior for the SAML Toolkit library. If other restrictions are needed, we should change the checkbox to a text input.)'),
      '#default_value' => $config->get('security_request_authn_context'),
    ];

    $form['security']['request_set_name_id_policy'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Specify NameID policy'),
      '#description' => $this->t('A NameIDPolicy element is added in authentication requests. This is default behavior for the SAML Toolkit library, but may be unneeded. If checked, "NameID Format" may need to be specified too. If unchecked, the "Require NameID" checkbox may need to be unchecked too.'),
      // This is one of the few checkboxes that must be TRUE on existing
      // installations where the checkbox didn't exist before (in older module
      // versions). Others get their default only from the config/install file.
      '#default_value' => $config->get('request_set_name_id_policy') ?? TRUE,
    ];

    $form['security']['sp_name_id_format'] = [
      '#type' => 'textfield',
      '#title' => $this->t('NameID Format'),
      '#description' => $this->t('The format for the NameID attribute to request from the identity provider. If "Specify NameID policy" is unchecked, this value is not included in authentication requests but is still included in the SP metadata. Some common formats (with "unspecified" being the default):<br>urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified<br>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress<br>urn:oasis:names:tc:SAML:2.0:nameid-format:transient<br>urn:oasis:names:tc:SAML:2.0:nameid-format:persistent'),
      '#default_value' => $config->get('sp_name_id_format'),
    ];

    $form['responses'] = [
      '#title' => $this->t('SAML Message Validation'),
      '#type' => 'details',
      '#open' => TRUE,
    ];

    $form['responses']['security_want_name_id'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require NameID'),
      '#description' => $this->t('The authentication response from the IdP must contain a NameID attribute. (This is default behavior for the SAML Toolkit library, but the SAML Authentication module does not use NameID values, so it seems this can be unchecked safely.)'),
      // This is one of the few checkboxes that must be TRUE on existing
      // installations where the checkbox didn't exist before (in older module
      // versions). Others get their default only from the config/install file.
      '#default_value' => $config->get('security_want_name_id') ?? TRUE,
    ];

    // This option's default value is FALSE but according to the SAML spec,
    // signing parameters should always be retrieved from the original request
    // instead of recalculated. (As argued in e.g.
    // https://github.com/onelogin/php-saml/issues/130.) The 'TRUE' option
    // (which was implemented in #6a828bf, as a result of
    // https://github.com/onelogin/php-saml/pull/37) reads the parameters from
    // $_SERVER['REQUEST'] but unfortunately this is not always populated in
    // all PHP/webserver configurations. IMHO the code should have a fallback
    // to other 'self encoding' methods if $_SERVER['REQUEST'] is empty; I see
    // no downside to that and it would enable us to always set TRUE / get rid
    // of this option in a future version of the SAML Toolkit library.
    // @todo file PR against SAML toolkit; note it in https://www.drupal.org/project/samlauth/issues/3131028
    $form['responses']['security_logout_reuse_sigs'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Retrieve logout signature parameters from \$_SERVER['REQUEST']"),
      '#description' => $this->t('Validation of logout requests/responses can fail on some IdPs (among others, ADFS) if this option is not set. This happens independently of the  "Strict validation" option.'),
      '#default_value' => $config->get('security_logout_reuse_sigs'),
    ];

    $form['responses']['strict'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Strict validation of responses'),
      '#description' => $this->t('Validation failures (partly based on the next options) will cause the SAML conversation to be terminated. In production environments, this <em>must</em> be set.'),
      '#default_value' => $config->get('strict'),
    ];

    $form['responses']['security_messages_sign'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require messages to be signed'),
      '#description' => $this->t('Responses (and logout requests) from the IdP are expected to be signed.'),
      '#default_value' => $config->get('security_messages_sign'),
      '#states' => [
        'disabled' => [
          ':input[name="strict"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['responses']['security_assertions_signed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require assertions to be signed'),
      '#description' => $this->t('Assertion elements in authentication responses from the IdP are expected to be signed. (When strict validation is turned off, this check is not performed but the expectation is still specified in the SP metadata.)'),
      '#default_value' => $config->get('security_assertions_signed'),
    ];

    $form['responses']['security_assertions_encrypt'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require assertions to be encrypted'),
      // The metadata changes if wantAssertionsEncrypted OR wantNameIdEncrypted
      // are set. But we don't have wantNameIdEncrypted yet, so we'll describe
      // this option as the way to change the metadata.
      '#description' => $this->t('Assertion elements in responses from the IdP are expected to be encrypted. (When strict validation is turned off, this check is not performed but the expectation is still specified in the SP metadata.)'),
      '#default_value' => $config->get('security_assertions_encrypt'),
    ];

    $form['other'] = [
      '#title' => $this->t('Other'),
      '#type' => 'details',
      '#open' => TRUE,
    ];

    $form['other']['use_base_url'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Use Drupal base URL in toolkit library"),
      '#description' => $this->t('This is supposedly a better version of the below that works for all Drupal configurations and (for reverse proxies) only uses HTTP headers/hostnames when you configured them as <a href=":trusted">trusted</a>. Please turn this on and file an issue if it doesn\'t work for you; it will be standard and non-configurable (and the above option will be removed) in the next major module versoin.', [
        ':trusted' => 'https://www.drupal.org/docs/installing-drupal/trusted-host-settings#s-trusted-host-security-setting-in-drupal-8',
      ]),
      '#default_value' => $config->get('use_base_url'),
    ];

    $form['other']['use_proxy_headers'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Use 'X-Forwarded-*' headers (deprecated)"),
      '#description' => $this->t("The SAML Toolkit will use 'X-Forwarded-*' HTTP headers (if present) for constructing/identifying the SP URL in sent/received messages. This used to be necessary if your SP is behind a reverse proxy."),
      '#default_value' => $config->get('use_proxy_headers'),
      '#states' => [
        'disabled' => [
          ':input[name="use_base_url"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['debugging'] = [
      '#title' => $this->t('Debugging'),
      '#type' => 'details',
      '#open' => TRUE,
    ];

    // This option has effect on signing of (login + logout) requests and
    // logout responses. It's badly named (in the SAML Toolkit;
    // "lowercaseUrlencoding") because there has never been any connection to
    // the case of URL-encoded strings. The only thing this does is use
    // rawurlencode() rather than urlencode() for URL encoding of signatures
    // sent to the IdP. This option arguably shouldn't even exist because the
    // use of urlencode() arguably is a bug that should just have been fixed.
    // (The name "lowercaseUrlencoding" seems to come from a mistake: it
    // originates from https://github.com/onelogin/python-saml/pull/144/files,
    // a PR for the signature validation code for incoming messages, which was
    // then mentioned in https://github.com/onelogin/php-saml/issues/136.
    // However, the latter / this option is about signature generation for
    // outgoing messages. Validation concerns different code, and is influenced
    // by the 'security_logout_reuse_sigs' option below, which has its own
    // issues.) This means that the default value should actually be TRUE.
    // @todo file PR against SAML toolkit; note it in https://www.drupal.org/project/samlauth/issues/3131028
    // @todo change default to TRUE; amend description (and d.o issue, and README
    $form['debugging']['security_lowercase_url_encoding'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("'Raw' encode signatures when signing messages"),
      '#description' => $this->t("If there is ever a reason to turn this option off, a bug report is greatly appreciated. (The module author believes this option is unnecessary and plans for a PR to the SAML Toolkit to re-document it / phase it out. If you installed this module prior to 8.x-3.0-alpha2 and this option is turned off already, that's fine - changing it should make no difference.)"),
      '#default_value' => $config->get('security_lowercase_url_encoding'),
      '#states' => [
        'disabled' => [
          ':input[name="security_authn_requests_sign"]' => ['checked' => FALSE],
          ':input[name="security_logout_requests_sign"]' => ['checked' => FALSE],
          ':input[name="security_logout_responses_sign"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['debugging']['debug_display_error_details'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Show detailed errors to the user"),
      '#description' => $this->t("This can help testing until SAML login/logout works. (Technical details about failed SAML login/logout are only logged to watchdog by default, to prevent exposing information about a misconfigured system / because it's unlikely they are useful.)"),
      '#default_value' => $config->get('debug_display_error_details'),
    ];

    $form['debugging']['debug_log_saml_out'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Log outgoing SAML messages"),
      '#description' => $this->t("Log messages which the SAML Toolkit 'sends' to the IdP (usually via the web browser through a HTTP redirect, as part of the URL)."),
      '#default_value' => $config->get('debug_log_saml_out'),
    ];

    $form['debugging']['debug_log_saml_in'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Log incoming SAML messages"),
      '#description' => $this->t("Log SAML responses (and logout requests) received by the ACS/SLS endpoints."),
      '#default_value' => $config->get('debug_log_saml_in'),
    ];

    $form['debugging']['debug_log_in'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Log incoming messages before validation"),
      '#description' => $this->t("Log supposed SAML messages received by the ACS/SLS endpoints before validating them as XML. If the other option logs nothing, this still might, but the logged contents may make less sense."),
      '#default_value' => $config->get('debug_log_in'),
    ];

    $form['debugging']['debug_phpsaml'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Enable debugging in SAML Toolkit library"),
      '#description' => $this->t("The exact benefit is unclear; as of library v3.4, this prints out certain validation errors to STDOUT / syslog, many of which would also be reported by other means. However, that might change..."),
      '#default_value' => $config->get('debug_phpsaml'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    // Validate login/logout redirect URLs.
    $login_url_path = $form_state->getValue('login_redirect_url');
    if ($login_url_path) {
      $login_url_path = $this->token->replace($login_url_path);
      $login_url = $this->pathValidator->getUrlIfValidWithoutAccessCheck($login_url_path);
      if (!$login_url) {
        $form_state->setErrorByName('login_redirect_url', $this->t('The Login Redirect URL is not a valid path.'));
      }
    }
    $logout_url_path = $form_state->getValue('logout_redirect_url');
    if ($logout_url_path) {
      $logout_url_path = $this->token->replace($logout_url_path);
      $logout_url = $this->pathValidator->getUrlIfValidWithoutAccessCheck($logout_url_path);
      if (!$logout_url) {
        $form_state->setErrorByName('logout_redirect_url', $this->t('The Logout Redirect URL is not a valid path.'));
      }
    }
    $error_redirect_url = $form_state->getValue('error_redirect_url');
    if ($error_redirect_url) {
      $error_redirect_url = $this->token->replace($error_redirect_url);
      $error_url = $this->pathValidator->getUrlIfValidWithoutAccessCheck($error_redirect_url);
      if (!$error_url) {
        $form_state->setErrorByName('error_redirect_url', $this->t('The Error redirect URL is not a valid path.'));
      }
    }

    $duration = $form_state->getValue('metadata_valid_secs');
    if ($duration || $duration == '0') {
      $duration = $this->parseReadableDuration($form_state->getValue('metadata_valid_secs'));
      if (!$duration) {
        $form_state->setErrorByName('metadata_valid_secs', $this->t('Invalid period value.'));
      }
    }

    // @todo Validate key/certs. Might be able to just openssl_x509_parse().
    $sp_key_type = $form_state->getValue('sp_key_cert_type');
    if ($sp_key_type) {
      list($sp_key_type, $sp_cert_type) = explode('_', $sp_key_type, 2);
    }
    else {
      $sp_cert_type = '';
    }
    $keyname = $form_state->getValue('sp_key_key');
    $cert_keyname = $form_state->getValue('sp_cert_key');
    if (in_array($sp_cert_type, ['', 'key']) && $cert_keyname && ($sp_key_type === 'key' || !$sp_key_type && !$keyname)) {
      // The select element for the private key is invisible. Get it from the
      // cert (except if that is empty; then we don't really care what happens
      // at this stage; we'll warn while displaying the form).
      $key = $this->keyRepository->getKey($cert_keyname);
      if ($key) {
        $key_type_settings = $key->getKeyType()->getConfiguration();
        if (!empty($key_type_settings['private_key'])) {
          $key = $this->keyRepository->getKey($key_type_settings['private_key']);
        }
      }
      $form_state->setValue('sp_key_key', $key ? $key->id() : '');
    }
    $filename = $form_state->getValue('sp_key_file');
    $full_cert = $form_state->getValue('sp_private_key');
    if ($filename && in_array($sp_key_type, ['', 'file']) && $filename[0] !== '/') {
      $form_state->setErrorByName('sp_key_file', $this->t('SP private key filename must be absolute.'));
    }
    // There are 4 elements that reference the key. At least 3 must be empty or
    // invisible. (Checking $sp_key_type=='' is enough to determine if multiple
    // elements are visible.)
    if (!$sp_key_type && (((int) empty($keyname)) + ((int) empty($cert_keyname)) + ((int) empty($filename)) + ((int) empty($full_cert))) < 3) {
      $form_state->setErrorByName("sp_private_key", $this->t('Only one private key (filename) element must be populated.'));
    }

    $filename = $form_state->getValue('sp_cert_file');
    $full_cert = $form_state->getValue('sp_x509_certificate');
    if ($filename && in_array($sp_cert_type, ['', 'file']) && $filename[0] !== '/') {
      $form_state->setErrorByName('sp_cert_file', $this->t('SP certificate filename must be absolute.'));
    }
    if (!$sp_cert_type && (($cert_keyname && $filename) || ($cert_keyname && $full_cert) || ($filename && $full_cert))) {
      $form_state->setErrorByName("sp_private_key", $this->t('Only one certificate (filename) element must be populated.'));
    }
    $keyname = $form_state->getValue('sp_new_cert_key');
    $filename = $form_state->getValue('sp_new_cert_file');
    $full_cert = $form_state->getValue('sp_new_cert');
    if ($filename && in_array($sp_cert_type, ['', 'file']) && $filename[0] !== '/') {
      $form_state->setErrorByName("sp_private_key", $this->t('Only one new certificate (filename) element must be populated.'));
    }
    if (!$sp_cert_type && (($keyname && $filename) || ($keyname && $full_cert) || ($filename && $full_cert))) {
      $form_state->setErrorByName("sp_new_cert", $this->t('Only one new certificate (filename) element must be populated.'));
    }

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
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory()->getEditable(SamlController::CONFIG_OBJECT_NAME);

    $sp_key_type = $form_state->getValue('sp_key_cert_type');
    if ($sp_key_type) {
      list($sp_key_type, $sp_cert_type) = explode('_', $sp_key_type, 2);
    }
    else {
      $sp_cert_type = '';
    }
    // We validated that max. 1 of the values is set if $sp_key/cert_type == ''.
    // If $sp_key/cert_type is nonempty, other values may be set which we must
    // explicitly skip.
    $sp_private_key = $form_state->getValue('sp_key_key');
    if ($sp_private_key && in_array($sp_key_type, ['', 'key'])) {
      // If 'key', the value was changed to the appropriate one in the
      // validate function (if necessary).
      $sp_private_key = "key:$sp_private_key";
    }
    if (!$sp_private_key && in_array($sp_key_type, ['', 'file'])) {
      $sp_private_key = $form_state->getValue('sp_key_file');
      if ($sp_private_key) {
        $sp_private_key = "file:$sp_private_key";
      }
    }
    if (!$sp_private_key && in_array($sp_key_type, ['', 'config'])) {
      $sp_private_key = $form_state->getValue('sp_private_key');
      if ($sp_private_key) {
        $sp_private_key = $this->formatKeyOrCert($sp_private_key, FALSE, TRUE);
      }
    }

    $sp_cert = $form_state->getValue('sp_cert_key');
    if ($sp_cert && in_array($sp_cert_type, ['', 'key'])) {
      // If 'key', the value was changed to the appropriate one in the
      // validate function (if necessary).
      $sp_cert = "key:$sp_cert";
    }
    if (!$sp_cert && in_array($sp_cert_type, ['', 'file'])) {
      $sp_cert = $form_state->getValue('sp_cert_file');
      if ($sp_cert) {
        $sp_cert = "file:$sp_cert";
      }
    }
    if (!$sp_cert && in_array($sp_cert_type, ['', 'config'])) {
      $sp_cert = $form_state->getValue('sp_x509_certificate');
      if ($sp_cert) {
        $sp_cert = $this->formatKeyOrCert($sp_cert, FALSE);
      }
    }

    $sp_new_cert = $form_state->getValue('sp_new_cert_key');
    if ($sp_new_cert && in_array($sp_cert_type, ['', 'key'])) {
      // If 'key', the value was changed to the appropriate one in the
      // validate function (if necessary).
      $sp_new_cert = "key:$sp_new_cert";
    }
    if (!$sp_new_cert && in_array($sp_cert_type, ['', 'file'])) {
      $sp_new_cert = $form_state->getValue('sp_new_cert_file');
      if ($sp_new_cert) {
        $sp_new_cert = "file:$sp_new_cert";
      }
    }
    if (!$sp_new_cert && in_array($sp_cert_type, ['', 'config'])) {
      $sp_new_cert = $form_state->getValue('sp_new_cert');
      if ($sp_new_cert) {
        $sp_new_cert = $this->formatKeyOrCert($sp_new_cert, FALSE);
      }
    }

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

    // This is never 0 but can be ''. (NULL would mean same as ''.) Unlike
    // others, this value needs to be unset if empty.
    $metadata_valid = $form_state->getValue('metadata_valid_secs');
    if ($metadata_valid) {
      $config->set('metadata_valid_secs', $this->parseReadableDuration($metadata_valid));
    }
    else {
      $config->clear('metadata_valid_secs');
    }

    $config
      ->set('login_menu_item_title', $form_state->getValue('login_menu_item_title'))
      ->set('logout_menu_item_title', $form_state->getValue('logout_menu_item_title'))
      ->set('logout_different_user', $form_state->getValue('logout_different_user'))
      ->set('local_login_saml_error', $form_state->getValue('local_login_saml_error'))
      ->set('login_redirect_url', $form_state->getValue('login_redirect_url'))
      ->set('logout_redirect_url', $form_state->getValue('logout_redirect_url'))
      ->set('drupal_login_roles', $form_state->getValue('drupal_login_roles'))
      ->set('error_redirect_url', $form_state->getValue('error_redirect_url'))
      ->set('error_throw', $form_state->getValue('error_throw'))
      ->set('sp_entity_id', $form_state->getValue('sp_entity_id'))
      ->set('sp_name_id_format', $form_state->getValue('sp_name_id_format'))
      ->set('sp_x509_certificate', $sp_cert)
      ->set('sp_new_certificate', $sp_new_cert)
      ->set('sp_private_key', $sp_private_key)
      ->set('metadata_cache_http', $form_state->getValue('metadata_cache_http'))
      ->set('idp_entity_id', $form_state->getValue('idp_entity_id'))
      ->set('idp_single_sign_on_service', $form_state->getValue('idp_single_sign_on_service'))
      ->set('idp_single_log_out_service', $form_state->getValue('idp_single_log_out_service'))
      ->set('idp_change_password_service', $form_state->getValue('idp_change_password_service'))
      ->set('idp_certs', $idp_certs)
      ->set('idp_cert_encryption', $idp_cert_encryption)
      ->set('unique_id_attribute', $form_state->getValue('unique_id_attribute'))
      ->set('map_users', $form_state->getValue('map_users'))
      ->set('map_users_name', $form_state->getValue('map_users_name'))
      ->set('map_users_mail', $form_state->getValue('map_users_mail'))
      ->set('map_users_roles', $form_state->getValue('map_users_roles'))
      ->set('create_users', $form_state->getValue('create_users'))
      ->set('sync_name', $form_state->getValue('sync_name'))
      ->set('sync_mail', $form_state->getValue('sync_mail'))
      ->set('user_name_attribute', $form_state->getValue('user_name_attribute'))
      ->set('user_mail_attribute', $form_state->getValue('user_mail_attribute'))
      ->set('security_authn_requests_sign', $form_state->getValue('security_authn_requests_sign'))
      ->set('security_logout_requests_sign', $form_state->getValue('security_logout_requests_sign'))
      ->set('security_logout_responses_sign', $form_state->getValue('security_logout_responses_sign'))
      ->set('security_assertions_encrypt', $form_state->getValue('security_assertions_encrypt'))
      ->set('security_assertions_signed', $form_state->getValue('security_assertions_signed'))
      ->set('security_lowercase_url_encoding', $form_state->getValue('security_lowercase_url_encoding'))
      ->set('security_messages_sign', $form_state->getValue('security_messages_sign'))
      ->set('request_set_name_id_policy', $form_state->getValue('request_set_name_id_policy'))
      ->set('security_want_name_id', $form_state->getValue('security_want_name_id'))
      ->set('security_logout_reuse_sigs', $form_state->getValue('security_logout_reuse_sigs'))
      ->set('security_request_authn_context', $form_state->getValue('security_request_authn_context'))
      ->set('security_signature_algorithm', $form_state->getValue('security_signature_algorithm'))
      ->set('strict', $form_state->getValue('strict'))
      ->set('use_proxy_headers', $form_state->getValue('use_proxy_headers'))
      ->set('use_base_url', $form_state->getValue('use_base_url'))
      ->set('debug_display_error_details', $form_state->getValue('debug_display_error_details'))
      ->set('debug_log_saml_out', $form_state->getValue('debug_log_saml_out'))
      ->set('debug_log_saml_in', $form_state->getValue('debug_log_saml_in'))
      ->set('debug_log_in', $form_state->getValue('debug_log_in'))
      ->set('debug_phpsaml', $form_state->getValue('debug_phpsaml'))
      ->clear('sp_cert_folder')
      ->save();

    parent::submitForm($form, $form_state);
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

  /**
   * Converts number of seconds into a human readable 'duration' string.
   *
   * @param int $seconds
   *   Number of seconds.
   *
   * @return string
   *   The human readable duration description (e.g. "5 hours 3 minutes").
   */
  protected function makeReadableDuration($seconds) {
    $calculation = [
      'week' => 3600 * 24 * 7,
      'day' => 3600 * 24,
      'hour' => 3600,
      'minute' => 60,
      'second' => 1,
    ];

    $duration = '';
    foreach ($calculation as $unit => $unit_amount) {
      $amount = (int) ($seconds / $unit_amount);
      if ($amount) {
        if ($duration) {
          $duration .= ', ';
        }
        $duration .= "$amount $unit" . ($amount > 1 ? 's' : '');
      }
      $seconds -= $amount * $unit_amount;
    }

    return $duration;
  }

  /**
   * Parses a human readable 'duration' string.
   *
   * @param string $expression
   *   The human readable duration description (e.g. "5 hours 3 minutes").
   *
   * @return int
   *   The number of seconds; 0 implies invalid duration.
   */
  protected function parseReadableDuration($expression) {
    $calculation = [
      'week' => 3600 * 24 * 7,
      'day' => 3600 * 24,
      'hour' => 3600,
      'minute' => 60,
      'second' => 1,
    ];
    $expression = strtolower(trim($expression));
    if (substr($expression, -1) === '.') {
      $expression = rtrim(substr($expression, 0, strlen($expression) - 1));
    }
    $seconds = 0;
    $seen = [];
    // Numbers must be numeric. Valid: "X hours Y minutes" possibly separated
    // by comma or "and". Months/years are not accepted because their length is
    // ambiguous.
    $parts = preg_split('/(\s+|\s*,\s*|\s+and\s+)(?=\d)/', $expression);
    foreach ($parts as $part) {
      if (!preg_match('/^(\d+)\s*((?:week|day|hour|min(?:ute)?|sec(?:ond)?)s?)$/', $part, $matches)) {
        return 0;
      }
      if (substr($matches[2], -1) === 's') {
        $matches[2] = substr($matches[2], 0, strlen($matches[2]) - 1);
      }
      elseif ($matches[1] != 1 && !in_array($matches[2], ['min', 'sec'], TRUE)) {
        // We allow "1 min", "1 mins", "2 min", not "2 minute".
        return 0;
      }
      $unit = $matches[2] === 'min' ? 'minute' : ($matches[2] === 'sec' ? 'second' : $matches[2]);
      if (!isset($calculation[$unit])) {
        return 0;
      }
      if (isset($seen[$unit])) {
        return 0;
      }
      $seen[$unit] = TRUE;
      $seconds += $calculation[$unit] * $matches[1];
    }

    return $seconds;
  }

}
