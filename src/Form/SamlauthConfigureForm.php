<?php

namespace Drupal\samlauth\Form;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Url;
use Drupal\Core\Utility\Token;
use Drupal\samlauth\Controller\SamlController;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a configuration form for miscellaneous samlauth module settings.
 *
 * Reads/writes the same config object as SamlauthSamlConfigureForm; the form
 * is split up because configuration options became unwieldy.
 */
class SamlauthConfigureForm extends ConfigFormBase {

  const MAX_UNCOLLAPSED_ROLES = 10;

  /**
   * The typed configuration manager.
   *
   * @var \Drupal\Core\Config\TypedConfigManagerInterface
   */
  protected $typedConfigManager;

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
   * Constructs a \Drupal\samlauth\Form\SamlauthConfigureForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed configuration manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The EntityTypeManager service.
   * @param \Drupal\Core\Path\PathValidatorInterface $path_validator
   *   The PathValidator service.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typed_config_manager, EntityTypeManagerInterface $entity_type_manager, PathValidatorInterface $path_validator, Token $token) {
    parent::__construct($config_factory);
    $this->typedConfigManager = $typed_config_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->pathValidator = $path_validator;
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('path.validator'),
      $container->get('token')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [SamlController::CONFIG_OBJECT_NAME];
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
    // The types and labels of all configuration values are defined in the
    // schema.yml; we want to get them from there instead of repeating them.
    // A simple definition array without replacements should suffice for this
    // purpose; it doesn't seem to make sense to wrap it in some typed
    // DataDefinition class...
    $schema_definition = $this->typedConfigManager->getDefinition(SamlController::CONFIG_OBJECT_NAME);
    assert(!empty($schema_definition['mapping']), 'Config schema of ' . SamlController::CONFIG_OBJECT_NAME . ' has unexpected value; ' . self::class . ' needs rework.');
    $schema_definition = $schema_definition['mapping'];

    $config = $this->config(SamlController::CONFIG_OBJECT_NAME);

    /** @var \Drupal\user\Entity\Role[] $roles */
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    unset($roles[UserInterface::ANONYMOUS_ROLE]);
    $role_options = [];
    foreach ($roles as $name => $role) {
      $role_options[$name] = $role->label();
    }
    $real_role_options = $role_options;
    unset($real_role_options[UserInterface::AUTHENTICATED_ROLE]);
    $collapse_rolesets = count($role_options) > self::MAX_UNCOLLAPSED_ROLES;

      $form['ui'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('User Interface'),
    ];

    // Show note for enabling "log in" or "log out" menu link item.
    if (Url::fromRoute('entity.menu.edit_form', ['menu' => 'account'])->access()) {
      $form['ui']['#description'] =
        '<em>' . $this->t('Note: You <a href="@url">may want to enable</a> the "log in" / "log out" menu item and disable the original one.', [
          '@url' => Url::fromRoute('entity.menu.edit_form', ['menu' => 'account'])
            ->toString(),
        ]) . '</em>';
    }

    $this->addElementsFromSchema($form['ui'], $schema_definition, $config, [
      'login_menu_item_title' => $this->t('The title of the SAML login link in the User account menu. Defaults to "Log in".'),
      'logout_menu_item_title' => $this->t('The title of the SAML logout link in the User account menu. Defaults to "Log out".'),
      // Login link has two settings (boolean + string) so it can be disabled
      // while still remembering the title.
      'login_link_show' => NULL,
      'login_link_title' => [
        '#description' => $this->t('Text to display as the link to SAML login.'),
        '#states' => [
          'disabled' => [
            ':input[name="login_link_show"]' => ['checked' => FALSE],
          ],
        ],
      ],
    ]);

    $form['user_info'] = [
      '#title' => $this->t('Drupal Login Using SAML Data'),
      '#type' => 'details',
      '#open' => TRUE,
      '#description' => $this->t('User creation / synchronization / Drupal login can proceed when <a href=":url">SAML communication</a> happens successfully.', [
        ':url' => Url::fromRoute('samlauth.saml_configure_form')->toString(),
      ]),
    ];

    $this->addElementsFromSchema($form['user_info'], $schema_definition, $config, [
      'unique_id_attribute' => $this->t("A SAML attribute whose value is unique per user and does not change over time. Its value is stored by Drupal and associated with the Drupal user who is logged in. (In principle, a non-transient NameID could also be used for this value; the SAML Authentication module does not support this yet.)<br>Example: <em>eduPersonPrincipalName</em> or <em>eduPersonTargetedID</em>"),
    ]);

    $form['user_info']['linking'] = [
      '#title' => $this->t('Attempt to link SAML data to existing Drupal users'),
      '#type' => 'details',
      '#open' => TRUE,
      '#description' => t('If enabled, whenever the unique ID in the SAML assertion is not already associated with a Drupal user but the assertion data can be matched with an existing Drupal user without SAML association, that user will be linked and logged in. Matching is attempted in the order of below enabled checkboxes, until a user is found.')
      . '<br><br><em>' . t('Warning: if the data used for matching can be changed by the IdP user, this has security implications; it enables a user to influence which Drupal user they take over.') . '</em>',
    ];

    $this->addElementsFromSchema($form['user_info']['linking'], $schema_definition, $config, [
      'map_users' => $this->t("Allows user matching by the included 'User Fields Mapping' module as well as any other code (event subscriber) installed for this purpose."),
      'map_users_name' => $this->t('Allows matching an existing Drupal user name with value of the user name attribute.'),
      'map_users_mail' => $this->t('Allows matching an existing Drupal user email with value of the user email attribute.'),
      'map_users_roles' => [
        '#description' => $this->t('If a matched account has <em>any</em> role that is not explicitly allowed here, linking/login is denied.'),
        '#options' => $role_options,
        '#states' => [
          'disabled' => [
            ':input[name="map_users"]' => ['checked' => FALSE],
            ':input[name="map_users_name"]' => ['checked' => FALSE],
            ':input[name="map_users_mail"]' => ['checked' => FALSE],
          ],
        ],
      ],
    ]);
    if ($collapse_rolesets) {
      $form['user_info']['linking']['roles'] = [
        '#title' => $this->t('Allowed roles'),
        '#type' => 'details',
        '#open' => FALSE,
      ];
      $form['user_info']['linking']['roles']['map_users_roles'] = $form['user_info']['linking']['map_users_roles'];
      $form['user_info']['linking']['map_users_roles'] = [];
    }

    $this->addElementsFromSchema($form['user_info'], $schema_definition, $config, [
      'create_users' => $this->t('If data in the SAML assertion is not associated with a Drupal user, a new user is created using the name / email attributes from the response.'),
      'sync_name' => $this->t('The name attribute in the SAML assertion is propagated to the associated Drupal user on every login. (When unchecked, the Drupal user name is not changed after user creation.)'),
      'sync_mail' => $this->t('The email attribute in the SAML assertion is propagated to the associated Drupal user on every login. (When unchecked, the Drupal user email is not changed after user creation.)'),
      'user_name_attribute' => [
        '#description' => $this->t('When users are linked / created, this field specifies which SAML attribute should be used for the Drupal user name.<br />Example: <em>cn</em> or <em>eduPersonPrincipalName</em>'),
        '#states' => [
          'disabled' => [
            ':input[name="map_users_name"]' => ['checked' => FALSE],
            ':input[name="create_users"]' => ['checked' => FALSE],
            ':input[name="sync_name"]' => ['checked' => FALSE],
          ],
        ],
      ],
      'user_mail_attribute' => [
        '#description' => $this->t('When users are linked / created, this field specifies which SAML attribute should be used for the Drupal email address.<br />Example: <em>mail</em>'),
        '#states' => [
          'disabled' => [
            ':input[name="map_users_mail"]' => ['checked' => FALSE],
            ':input[name="create_users"]' => ['checked' => FALSE],
            ':input[name="sync_mail"]' => ['checked' => FALSE],
          ],
        ],
      ],
    ]);

    $form['login_logout'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Login / Logout'),
    ];

    if ($collapse_rolesets) {
      $form['login_logout']['roles'] = [
        '#title' => $this->t('Roles allowed Drupal login'),
        '#type' => 'details',
        '#open' => FALSE,
      ];
    }
    $this->addElementsFromSchema($form['login_logout'], $schema_definition, $config, [
      'drupal_login_roles' => [
        '#description' => $this->t('Users who have previously logged in through the SAML Identity Provider can only use the standard Drupal login method if they have one of the roles selected here. Preexisting Drupal users who have never logged in through the IdP are not affected by this restriction.'),
        '#options' => $role_options,
      ],
      'local_login_saml_error' => [
        '#description' => $this->t('If not checked, we show the generic "Unrecognized username or password" message to users who cannot use the standard Drupal login method. This prevents disclosing information about whether the account name exists, but is untrue / potentially confusing.', [
          ':permission' => Url::fromUri('base:admin/people/permissions', ['fragment' => 'module-samlauth'])->toString(),
        ]),
        // TRUE on existing installations where the checkbox didn't exist before;
        // FALSE on new installations.
        '#default_value' => $config->get('local_login_saml_error') ?? TRUE,
      ],
      'idp_change_password_service' => $this->t('URL where disallowed users (who do not have a Drupal password) will be directed to change their password. This is shown on their account edit form.'),
      'logout_different_user' => $this->t('If a login (coming from the IdP) happens while another user is still logged into the site, that user is logged out and the new user is logged in. (By default, the old user stays logged in and a warning is displayed. This situation does not apply if the IdP is on another domain and <a href="https://www.drupal.org/node/3275352">cookie_samesite is configured</a> as "Strict" or "Lax", as is standard for new D10.1+ installs, because then the old user is not seen while coming from the IdP.)'),
      'login_redirect_url' => $this->t("The default URL to redirect the user to after login. This should be an internal path starting with a slash, or an absolute URL. Defaults to the logged-in user's account page."),
      'logout_redirect_url' => $this->t('The default URL to redirect the user to after logout. This should be an internal path starting with a slash, or an absolute URL. Defaults to the front page.'),
      'error_redirect_url' => [
        '#description' => $this->t("The default URL to redirect the user to after an error occurred. This should be an internal path starting with a slash, or an absolute URL. Defaults to the front page."),
        '#states' => [
          'disabled' => [
            ':input[name="error_throw"]' => ['checked' => TRUE],
          ],
        ],
      ],
      'error_throw' => $this->t("No redirection or meaningful logging is done. This better enables custom code to handle errors."),
      'bypass_relay_state_check' => $this->t("When checked, a response's RelayState parameter is redirected to, even if not a known safe hostname. (This will be removed in a newer version of the module.)"),
    ]);
    if ($collapse_rolesets) {
      $form['login_logout']['roles']['drupal_login_roles'] = $form['login_logout']['drupal_login_roles'];
      $form['login_logout']['drupal_login_roles'] = [];
    }

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
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config(SamlController::CONFIG_OBJECT_NAME);

    foreach ([
      'login_menu_item_title',
      'logout_menu_item_title',
      'logout_different_user',
      'local_login_saml_error',
      'login_redirect_url',
      'logout_redirect_url',
      'error_redirect_url',
      'error_throw',
      'bypass_relay_state_check',
      'login_link_show',
      'login_link_title',
      'unique_id_attribute',
      'map_users',
      'map_users_name',
      'map_users_mail',
      'create_users',
      'sync_name',
      'sync_mail',
      'user_name_attribute',
      'user_mail_attribute',
    ] as $config_value) {
      $config->set($config_value, $form_state->getValue($config_value));
    }
    // Filter out 0 inputs from multivalue checkboxes.
    foreach ([
      'drupal_login_roles',
      'map_users_roles',
    ] as $config_value) {
      $config->set($config_value, array_filter($form_state->getValue($config_value)));
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Adds form elements using the type and title found in the config schema.
   *
   * This way we don't need to define these in two places. (If we don't define
   * them in the schema, configuration translation/inspector forms look strange;
   * at least the translation form is important.)
   */
  protected function addElementsFromSchema(array &$build, array $schema_definition, Config $config, array $elements) {
    foreach ($elements as $key => $data) {
      assert(!empty($schema_definition[$key]['type']), "'$key.type' not found in schema definition for samlauth.authentication.");

      $label = $schema_definition[$key]['label'] ?? 'Label not found.';
      $default_default = NULL;
      switch ($schema_definition[$key]['type']) {
        case 'boolean':
          $type = 'checkbox';
          break;

        case 'string':
        case 'label':
          $type = 'textfield';
          break;

        case 'text':
          $type = 'textarea';
          break;

        case 'integer':
          $type = 'number';
          break;

        case 'sequence':
          // This one is very much specific to our situation.
          $type = 'checkboxes';
          $default_default = [];
          assert(!empty($data['#options']), "No #options set for $key (type=sequence).");
          break;

        default:
          $type = '';
      }
      // We must only call this helper function for simple elements.
      assert(!empty($type), "Unrecognized type $type in addElementsFromSchema().");

      $build[$key] = [
        '#type' => $type,
        // A label of any config element (as defined in the schema.yml) is
        // translatable through 'UI translation'.
        '#title' => $this->t($label),
        '#default_value' => $config->get($key),
      ];
      if (isset($default_default) && !isset($build[$key]['#default_value'])) {
        $build[$key]['#default_value'] = $default_default;
      }
      if (is_array($data)) {
        $build[$key] = array_merge($build[$key], $data);
      }
      elseif ($data) {
        $build[$key]['#description'] = $data;
      }
    }
  }

}
