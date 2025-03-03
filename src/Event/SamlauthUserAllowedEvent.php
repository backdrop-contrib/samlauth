<?php

namespace Drupal\samlauth\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Wraps a samlauth user allowed event for event listeners.
 */
class SamlauthUserAllowedEvent extends Event {

  /**
   * The SAML attributes received from the IdP.
   *
   * Single values are typically represented as one-element arrays.
   *
   * @var array
   */
  protected array $attributes;

  /**
   * Whether access is allwed.
   *
   * @var bool
   */
  protected $isAllowed = TRUE;

  /**
   * Constructs a samlouth user link event object.
   *
   * @param array $attributes
   *   The SAML attributes received from the IdP.
   */
  public function __construct(array $attributes) {
    $this->attributes = $attributes;
  }

  /**
   * Gets the SAML attributes.
   *
   * @return array
   *   The SAML attributes received from the IdP.
   */
  public function getAttributes(): array {
    return $this->attributes;
  }

  /**
   * Disallows login.
   */
  public function disallow(): void {
    $this->isAllowed = FALSE;
  }

  /**
   * Allows to login.
   *
   * This is the default and only need to be used to overrule a previous
   * disallow.
   */
  public function allow(): void {
    $this->isAllowed = TRUE;
  }

  /**
   * Returns whether the login is allowed.
   *
   * @return bool
   *   Whether the user is allowed to login.
   */
  public function isAllowed(): bool {
    return $this->isAllowed;
  }

}
