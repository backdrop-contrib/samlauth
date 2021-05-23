<?php

namespace Drupal\key_asymmetric\Plugin\KeyType;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\Exception\KeyException;
use Drupal\key\Plugin\KeyTypeBase;
use Drupal\key\Plugin\KeyPluginFormInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Defines a fairly generic key type for private keys.
 *
 * @KeyType(
 *   id = "asymmetric_private",
 *   label = @Translation("Private key"),
 *   description = @Translation("Private keys, by definition, are mostly used for decrypting data encrypted with a public key and signing data. Most standard key formats include the corresponding public key, in which case the stored key(pair) could also be used for encryption."),
 *   group = "asymmetric_private",
 *   key_value = {
 *     "plugin" = "textarea_field"
 *   }
 * )
 */
class AsymmetricPrivateKeyType extends KeyTypeBase implements KeyPluginFormInterface {

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('request_stack')->getCurrentRequest()
    );
  }

  /**
   * Constructs a \Drupal\Component\Plugin\PluginBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Request $request) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->request = $request;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // We'd love to
    // - move this section including the 'Info' button down just above/next to
    //   the 'Save' button;
    // - remove the "Strip trailing line breaks" checkboxes from e.g. the file
    //   provider, because it doesn't matter for us.
    // This is possible in a form_alter hook, but that part of the form doesn't
    // refresh with the AJAX reload that changes the key type.
    // The 'skip key validation' checkbox does not make sense if we just want
    // to use the "Info" button in the edit form just for getting the existing
    // key's info. But it does if we are editing the key value. Just getting
    // the existing key's info should ideally be possible in another way.
    $form['skip_validation'] = [
      '#title' => $this->t('Skip key validation'),
      '#type' => 'checkbox',
      '#description' => $this->t("Accept any value without trying to recognize it as a private key, and skip saving metadata which might be required to use the key."),
      '#default_value' => FALSE,
    ];
    $form['passphrase'] = [
      '#title' => $this->t('Private key passphrase (validation only)'),
      '#type' => 'password',
      '#description' => $this->t('This is only used to validate the key when pressing "Save" or "Info"; it is not stored.'),
      '#default_value' => '',
      '#states' => [
        'disabled' => [
          ':input[name="key_type_settings[validate][asymmetric_skip_validation]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    if (!$this->request->isSecure()) {
      $form['passphrase']['#description'] .= ' '
        . $this->t('<strong>Warning:</strong> do not send your passphrase over an insecure connection.');
    }
    $form['info_info'] = [
      '#type' => 'markup',
      '#markup' => $this->t('To get info about the key, first enter its data below and then press "Info".'),
    ];
    $form['get_info'] = [
      '#type' => 'submit',
      '#value' => $this->t('Info'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Mandatory because we implement (Key)PluginFormInterface.
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->setConfiguration($form_state->getValues());
  }

  /**
   * {@inheritdoc}
   */
  public function validateKeyValue(array $form, FormStateInterface $form_state, $key_value) {
    $property_info = [
      'format' => 'Format: @value',
      'algo' => 'Encryption algorithm: @value',
      'hash_algo' => 'Hashing algorithm: @value',
      'key_size' => 'Key size: @value bits',
      'comment' => 'Comment: @value',
      'has_public' => 'Has public key: @value',
    ];
    $key_type_settings = array_diff_key($form_state->getValues(), $property_info);
    $skip_validation = $key_type_settings['skip_validation'];
    $password = $key_type_settings['passphrase'];
    unset($key_type_settings['skip_validation'], $key_type_settings['passphrase'], $key_type_settings['get_info']);

    // Depending on triggering button: display info, or add/reset settings.
    $properties = [];
    $trigger = $form_state->getTriggeringElement();
    $display_info = (string) $trigger['#value'] == $this->t('Info');
    if ($display_info || !$skip_validation) {
      try {
        $properties = key_asymmetric_get_key_properties($key_value, $password);
        if (($properties['type'] ?? NULL) !== 'private') {
          $form_state->setErrorByName('key_value', $this->t('Value is not recognized as a private key; its type is "@type".', ['@type' => $properties['type'] ?? '<not set>']));
          $properties = [];
        }
        unset($properties['type']);
      }
      catch (\Exception $e) {
        $form_state->setErrorByName('key_value', $this->t('Error validating value for key: @error', [
          '@error' => $e->getMessage(),
        ]));
      }
      if ($properties && $display_info) {
        $info = [];
        foreach ($property_info as $property_name => $message) {
          if (isset($properties[$property_name])) {
            // This property is expected to be a scalar but isn't always; e.g.
            // key_size is an array for some formats. Then json_encode it so we
            // still output all info in one line. (We do the same for booleans
            // so they come out as true/false rather than ""/1.)
            $value = is_array($properties[$property_name]) || is_bool($properties[$property_name])
              ? (function_exists('json_encode') ? json_encode($properties[$property_name]) : var_export($properties[$property_name], TRUE))
              : $properties[$property_name];
            $info[] = $this->t($message, ['@value' => $value]);
          }
        }
        $this->messenger()->addStatus(new FormattableMarkup(implode('<br>', $info), []));
      }
    }

    if (!$form_state->getErrors()) {
      if ($display_info) {
        // This doesn't work because we don't have the full form state here.
        // @todo submit patch to copy $form_state::rebuild into parent form?
        $form_state->setRebuild();
        $form_state->setErrorByName('key_value', $this->t("(Ignore this message; it's not a real error.)"));
      }
      else {
        // Set/add all extracted properties to the key_type_settings; unset
        // expected properties from getKeyProperties() which we didn't get.
        $form_state->setValues($properties + $key_type_settings);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function generateKeyValue(array $configuration) {
    // Mandatory because we implement (Key)TypeInterface.
    // @todo We could support this - with a "YMMV"  / security warning...
    throw new KeyException('Creating a new private key is unsupported.');
  }

}
