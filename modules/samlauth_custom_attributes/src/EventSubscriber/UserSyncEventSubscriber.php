<?php

namespace Drupal\samlauth_custom_attributes\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\samlauth_custom_attributes\Event\SamlauthCustomAttributesEvents;
use Drupal\samlauth_custom_attributes\Event\SamlauthCustomAttributesCustomUserFieldEvent;
use Drupal\samlauth\Event\SamlauthEvents;
use Drupal\samlauth\Event\SamlauthUserSyncEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class UserSyncEventSubscriber
 *
 * Does some extra processing to the user sync to manage mapped fields, and to
 * dispatch the custom field events for fields that need more processing.
 *
 * @package Drupal\samlauth_custom_attributes\EventSubscriber
 */
class UserSyncEventSubscriber implements EventSubscriberInterface {

  /**
   * A configuration object containing mapping settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * UserSyncEventSubscriber constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   */
  public function __construct(ConfigFactoryInterface $config_factory, EventDispatcherInterface $event_dispatcher) {
    $this->config = $config_factory->get('samlauth_custom_attributes.mappings');
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[SamlauthEvents::USER_SYNC][] = ['onUserSync'];
    return $events;
  }

  /**
   * Performs actions to synchronize attributes to user fields.
   *
   * @param \Drupal\samlauth\Event\SamlauthUserSyncEvent $event
   *   The event.
   */
  public function onUserSync(SamlauthUserSyncEvent $event) {
    // Get the user account from the event.
    $account = $event->getAccount();

    // Get the mappings.
    $mappings = $this->config->get('mappings');

    // Synchronize attribute to field.
    foreach ($mappings as $id => $mapping) {
      // Get value from the SAML attribute.
      $attribute = $this->getAttributeByName($mapping['attribute_name'], $event);

      // If this is a custom field, dispatch an event with the originating
      // event and the name of the attribute to get out of the SAML.
      if ($mapping['field_name'] === 'custom') {
        $custom_field_event = new SamlauthCustomAttributesCustomUserFieldEvent($event, $mapping['attribute_name']);
        $this->eventDispatcher->dispatch(SamlauthCustomAttributesEvents::CUSTOM_FIELD, $custom_field_event);
      }
      // Otherwise, we just copy the data into the mapped field.
      else {
        $account->set($mapping['field_name'], $attribute);
      }
    }

    // We'll just assume there were changes to make sure everything is up to date.
    $event->markAccountChanged();
  }

  /**
   * Returns value from a SAML attribute whose name is configured in our module.
   *
   * This is suitable for single-value attributes.
   *
   * @param string $name
   *   The name of a SAML attribute.
   * @param \Drupal\samlauth\Event\SamlauthUserSyncEvent $event
   *   The event, which holds the attributes from the SAML response.
   *
   * @return mixed|null
   *   The SAML attribute value; NULL if the attribute value was not found.
   */
  public function getAttributeByName($name, SamlauthUserSyncEvent $event) {
    $attributes = $event->getAttributes();
    return $name && !empty($attributes[$name][0]) ? $attributes[$name][0] : NULL;
  }

}
