<?php

namespace Drupal\samlauth\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Wraps a samlauth library config alter event for event listeners.
 *
 * Allows subscribers to alter the OneLogin SAML PHP Toolkit configuration
 * array before the Auth object is instantiated. This enables customization of
 * advanced SAML settings that are not exposed in the module's UI.
 *
 * For the full configuration structure, see:
 * https://github.com/SAML-Toolkits/php-saml#settings
 */
class SamlauthLibraryConfigAlterEvent extends Event {

  /**
   * The OneLogin SAML configuration array.
   *
   * @var array
   */
  protected $config;

  /**
   * The purpose for this configuration.
   *
   * Possible values: 'metadata', 'login', 'acs', 'logout', 'sls-request',
   * 'sls-response', or '' (empty string for generic/any purpose).
   *
   * @var string
   */
  protected $purpose;

  /**
   * Constructs a samlauth library config alter event object.
   *
   * @param array $config
   *   The OneLogin SAML configuration array.
   * @param string $purpose
   *   The purpose for this configuration.
   */
  public function __construct(array $config, $purpose = '') {
    $this->config = $config;
    $this->purpose = $purpose;
  }

  /**
   * Gets the OneLogin SAML configuration array.
   *
   * @return array
   *   The configuration array.
   */
  public function getConfig() {
    return $this->config;
  }

  /**
   * Sets the OneLogin SAML configuration array.
   *
   * Event subscribers should use this to alter the configuration before the
   * Auth object is instantiated. The configuration follows the structure
   * documented in the SAML PHP Toolkit.
   *
   * @param array $config
   *   The configuration array.
   */
  public function setConfig(array $config) {
    $this->config = $config;
  }

  /**
   * Gets the purpose for this configuration.
   *
   * The purpose indicates what operation will be performed with this
   * configuration. This allows event subscribers to apply different
   * alterations based on the context.
   *
   * @return string
   *   The purpose string. Possible values: 'metadata', 'login', 'acs',
   *   'logout', 'sls-request', 'sls-response', or '' (empty).
   */
  public function getPurpose() {
    return $this->purpose;
  }

}
