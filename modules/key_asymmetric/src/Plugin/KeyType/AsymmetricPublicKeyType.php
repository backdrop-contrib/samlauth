<?php

namespace Drupal\key_asymmetric\Plugin\KeyType;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\Exception\KeyException;
use Drupal\key\Plugin\KeyTypeBase;
use Drupal\key\Plugin\KeyPluginFormInterface;

/**
 * Defines a key type for public keys and/or X.509 certificates.
 *
 * @KeyType(
 *   id = "asymmetric_public",
 *   label = @Translation("Public key/certificate"),
 *   description = @Translation("Public key in any common format, or certificate in X.509 format."),
 *   group = "asymmetric_public",
 *   key_value = {
 *     "plugin" = "textarea_field"
 *   }
 * )
 */
class AsymmetricPublicKeyType extends KeyTypeBase implements KeyPluginFormInterface {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $key_type_settings = $this->getConfiguration();
    $form['private_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Private key'),
      '#empty_option' => $this->t('- Select a private key -'),
      '#description' => $this->t('If this application also keeps the corresponding private key and requires knowing the relationship to this public key/certificate, reference it here.'),
      '#key_filters' => ['type' => 'asymmetric_private'],
      '#key_description' => FALSE,
      '#default_value' => $key_type_settings['private_key'] ?? NULL,
    ];

    // We'd love to
    // - move this section including the 'info' button down just above/next to
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
      '#title' => $this->t('Skip validation'),
      '#type' => 'checkbox',
      '#description' => $this->t("Accept any value without trying to recognize it as a public key/certificate, and skip saving metadata which might be required to use the key."),
      '#default_value' => FALSE,
    ];
    $form['info_info'] = [
      '#type' => 'markup',
      '#markup' => $this->t('To get info about the key/certificate, first enter its data below and then press "Info".'),
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
      'fingerprint' => 'Fingerprint: @value',
      'comment' => 'Comment: @value',
      'cert' => [
        'subject' => 'Subject info: @value',
        'issuer' => 'Issuer info: @value',
        'not_before' => 'Valid at/after: @value',
        'not_after' => 'Valid until: @value',
        'validity' => 'Validity: @value',
      ],
    ];
    $key_type_settings = array_diff_key($form_state->getValues(), $property_info);
    $skip_validation = $key_type_settings['skip_validation'];
    unset($key_type_settings['skip_validation'], $key_type_settings['get_info']);

    // Depending on triggering button: display key info, or (re)set properties.
    $properties = [];
    $trigger = $form_state->getTriggeringElement();
    $display_info = (string) $trigger['#value'] == $this->t('Info');
    if ($display_info || !$skip_validation) {
      try {
        $properties = key_asymmetric_get_key_properties($key_value);
        if (($properties['type'] ?? NULL) !== 'public') {
          $form_state->setErrorByName('key_value', $this->t('Value is not recognized as a public key/certificate; its type is "@type".', ['@type' => $properties['type'] ?? '<not set>']));
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
        $info = $this->constructInfo($property_info, $properties);
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
    // @todo We could support this - with a "YMMV" / security warning...
    throw new KeyException('Creating a new public key is unsupported.');
  }

  /**
   * Given a set of properties and related metadata, construct info array.
   *
   * @param array $property_metadata
   *   A set of property metadata (property key -> message template). Only the
   *   properties (which have a value) mentioned in here are returned.
   * @param array $properties
   *   The property values.
   *
   * @return array
   *   Array of messages describing each property mentioned in the metadata
   *   which has a value.
   */
  private function constructInfo(array $property_metadata, array $properties) {
    $info = [];
    foreach ($property_metadata as $property_name => $message_or_subarray) {
      if (isset($properties[$property_name])) {
        $property_is_array = is_array($properties[$property_name]);
        if (is_array($message_or_subarray)) {
          // We don't have a descriptive name for the property _array_; the
          // key will do:
          $prefix_name = ucfirst($property_name);
          if ($property_is_array) {
            foreach ($this->constructInfo($message_or_subarray, $properties[$property_name]) as $message) {
              $info[] = "$prefix_name > $message";
            }
          }
          else {
            // This shouldn't happen:
            $info[] = $this->t("@name (was expected to be an array): @value", [
              '@name' => $prefix_name,
              '@value' => $properties[$property_name],
            ]);
          }
        }
        else {
          // This property is expected to be a scalar but isn't always; e.g.
          // key_size is an array for some formats. Then json_encode it so we
          // still output all info in one line. (This shouldn't happen.)
          $value = $property_is_array || is_bool($properties[$property_name])
            ? (function_exists('json_encode') ? json_encode($properties[$property_name]) : var_export($properties[$property_name], TRUE))
            : $properties[$property_name];
          $info[] = $this->t($message_or_subarray, ['@value' => $value]);
        }
      }
    }
    return $info;
  }

}
