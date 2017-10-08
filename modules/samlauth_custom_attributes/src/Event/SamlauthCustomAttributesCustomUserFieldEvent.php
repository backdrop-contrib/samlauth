<?php
/**
 * Created by IntelliJ IDEA.
 * User: jbaker
 * Date: 7/14/17
 * Time: 2:11 PM
 */

namespace Drupal\samlauth_custom_attributes\Event;


use Drupal\samlauth\Event\SamlauthUserSyncEvent;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class SamlauthCustomAttributesCustomUserFieldEvent
 *
 * Defines the event class to handle custom field events.
 *
 * @package Drupal\samlauth_custom_attributes\Event
 */
class SamlauthCustomAttributesCustomUserFieldEvent extends Event {

  /**
   * @var \Drupal\samlauth\Event\SamlauthUserSyncEvent
   */
  protected $originatingEvent;

  /**
   * @var string
   */
  protected $attributeName;

  /**
   * SamlauthCustomAttributesCustomUserFieldEvent constructor.
   *
   * @param \Drupal\samlauth\Event\SamlauthUserSyncEvent $originating_event
   * @param string $attribute_name
   */
  public function __construct(SamlauthUserSyncEvent $originating_event, $attribute_name) {
    $this->originatingEvent = $originating_event;
    $this->attributeName = $attribute_name;
  }

  /**
   * @return \Drupal\samlauth\Event\SamlauthUserSyncEvent
   */
  public function getOriginatingEvent() {
    return $this->originatingEvent;
  }

  /**
   * @return mixed
   */
  public function getAttributeName() {
    return $this->attributeName;
  }

}
