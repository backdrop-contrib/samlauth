<?php

namespace Drupal\samlauth\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Wraps a samlauth idp switch event for event listeners.
 */
class SamlauthIdpSwitch extends Event {

  /**
   * The selected idp.
   *
   * The selected idp at the moment. Will default to whatever is set in the
   * config for default_idp.
   *
   * @var string
   */
  protected string $selectedIdp;

  /**
   * Constructs a samlauth idp switch event object.
   *
   * @param string $selectedIdp
   *   The IDP id that we want to load.
   */
  public function __construct(string $selectedIdp) {
    $this->selectedIdp = $selectedIdp;
  }

  /**
   * Returns the selected idp at the moment.
   *
   * @return string
   *   The id of the selected idp.
   */
  public function getSelectedIdp() {
    return $this->selectedIdp;
  }

  /**
   * Sets the id of the idp to use.
   *
   * @param string
   *   The id of the IDP to use.
   */
  public function setSelectedIdp(string $idp) {
    $this->selectedIdp = $idp;
  }

}
