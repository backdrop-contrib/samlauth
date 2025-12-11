<?php

namespace Drupal\samlauth_user_roles\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\samlauth\Event\SamlauthEvents;
use Drupal\samlauth\Event\SamlauthUserSyncEvent;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for the samlauth_user_roles module.
 */
class UserRolesEventSubscriber implements EventSubscriberInterface {

  /**
   * Name of the configuration object containing the setting used by this class.
   */
  const CONFIG_OBJECT_NAME = 'samlauth_user_roles.mapping';

  /**
   * The configuration factory service.
   *
   * We're doing $configFactory->get() all over the place to access our
   * configuration, which (despite its convoluted-ness) is actually a little
   * more efficient than storing the config object in a variable in this class.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new SamlauthUsersyncEventSubscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The EntityTypeManager service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerInterface $logger, EntityTypeManagerInterface $entity_type_manager) {
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[SamlauthEvents::USER_SYNC][] = ['onUserSync'];
    return $events;
  }

  /**
   * Assigns/unassigns roles as needed during user sync.
   *
   * @param \Drupal\samlauth\Event\SamlauthUserSyncEvent $event
   *   The event being dispatched.
   */
  public function onUserSync(SamlauthUserSyncEvent $event) {
    $config = $this->configFactory->get(static::CONFIG_OBJECT_NAME);
    if ($config->get('only_first_login') && !$event->isFirstLogin()) {
      return;
    }

    /** @var \Drupal\user\Entity\Role[] $valid_roles */
    $valid_roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    unset($valid_roles[UserInterface::ANONYMOUS_ROLE]);
    unset($valid_roles[UserInterface::AUTHENTICATED_ROLE]);
    $account = $event->getAccount();
    $changed_role_ids = $account_role_ids = $account->getRoles();

    // @todo better formalize what the 2 config values contain.
    //   - It should be an array of role IDs. (Use 'ids' not 'names' in
    //     below variable names, for clarity.)
    //   - In the form, we set an array of ID => ID, so it looks like we also
    //     keep the keys. Maybe this is already being converted into numeric
    //     keys by the newer config schema, or maybe not.
    //     - If yes: make a note in the changelog?
    //     - If no: change this some time (wait until v4?)
    // Remove 'unassign' roles, then add 'default' roles to $changed_role_ids.
    $role_names = $config->get('unassign_roles');
    if ($role_names) {
      if (is_array($role_names)) {
        $changed_role_ids = array_diff(
          $changed_role_ids,
          $this->getRoleIds($role_names, $valid_roles, 'unassign_roles')
        );
      }
      else {
        // Spam logs until configuration is fixed.
        $this->logger->warning('Invalid %name configuration value; skipping role unassignment.', ['%name' => 'unassign_roles']);
      }
    }

    $role_names = $config->get('default_roles');
    if ($role_names) {
      if (is_array($role_names)) {
        $changed_role_ids = array_unique(array_merge(
          $changed_role_ids,
          $this->getRoleIds($role_names, $valid_roles, 'default_roles')
        ));
      }
      else {
        $this->logger->warning('Invalid %name configuration value; skipping part of role assignment.', ['%name' => 'default_roles']);
      }
    }

    $idp_role_values = $this->getIdpRoles($event->getAttributes());
    $value_map = $config->get('value_map');
    if ($value_map) {
      if (!is_array($value_map)) {
        $this->logger->warning('%name is not an array; skipping role mapping.', ['%name' => 'value_map']);
        $idp_role_values = [];
      }
      elseif (!$config->get('saml_attribute')) {
        // We expect both config values or neither to be set. Spam logs if not.
        $this->logger->warning('%name is not configured; skipping role mapping.', ['%name' => 'saml_attribute']);
      }
      else {
        // Skip incomplete mapping config silently; it can be found by config
        // inspector.
        $value_map = array_filter(
          $value_map,
          fn($v) => isset($v['attribute_value']) && isset($v['role_machine_name']),
        );
      }
    }
    // Process role mapping (add to $changed_role_ids). Spam logs about
    // anything strange in the attribute values or value_map configuration.
    // (The logs don't mention the associated account, because it's possible
    // that the account has no ID or name yet. Maybe the log messages should be
    // doublechecked to make sure it's clear that they come from this class.)
    if ($idp_role_values) {
      if (!$value_map) {
        // Treat attribute values as Drupal role machine names.
        $value_map = array_map(
          fn($role) => ['attribute_value' => $role->id(), 'role_machine_name' => $role->id()],
          $valid_roles
        );
      }
      // Process values (add IDs of mapped roles); skip unknown values.
      foreach (array_map('trim', $idp_role_values) as $idp_role_value) {
        // The same IdP value can be mapped to multiple roles, so loop through
        // all defined mappings. If we find any illegal configuration, that
        // could mean we log duplicate warnings.
        $mapped = FALSE;
        foreach ($value_map as $mapping) {
          if ($idp_role_value === $mapping['attribute_value']) {
            // Attribute value matches role mapping. If the mapped role
            // exists (which we could have checked outside the loop), map it.
            $mapped = TRUE;
            if (isset($valid_roles[$mapping['role_machine_name']])) {
              $changed_role_ids[] = $valid_roles[$mapping['role_machine_name']]->id();
            }
            else {
              $this->logger->warning('Unknown/invalid role %role in %name configuration value; (partially?) skipping role assignment.', [
                '%name' => 'value_map',
                '%role' => $mapping['role_machine_name'],
              ]);
            }
          }
        }
        if (!$mapped) {
          $this->logger->warning('Role %idprole from IdP is not present in %name configuration value; role assignment was partially skipped.', [
            '%idprole' => $idp_role_value,
            '%name' => 'value_map',
          ]);
        }
      }
      $changed_role_ids = array_unique($changed_role_ids);
    }

    sort($account_role_ids);
    sort($changed_role_ids);
    if ($changed_role_ids != $account_role_ids) {
      foreach (array_diff($account_role_ids, $changed_role_ids) as $role_id) {
        $account->removeRole($role_id);
      }
      foreach (array_diff($changed_role_ids, $account_role_ids) as $role_id) {
        $account->addRole($role_id);
      }
      $event->markAccountChanged();
    }
  }

  /**
   * Extract (not yet mapped) values for roles from a SAML attribute.
   *
   * @param array $attributes
   *   The SAML attribute values, contained in the event.
   *
   * @return string[]
   *   The 'roles' contained in the attribute.
   */
  protected function getIdpRoles(array $attributes) {
    $idp_role_values = [];
    $config = $this->configFactory->get(static::CONFIG_OBJECT_NAME);
    $attribute_name = $config->get('saml_attribute');
    if ($attribute_name) {
      if (isset($attributes[$attribute_name])) {
        // Don't differentiate between several 'IdP role' values concatenated
        // in one attribute value, a multi-value attribute or a combination of
        // both. Get all 'IdP role' values into one array.
        if (!is_array($attributes[$attribute_name])) {
          // We've never seen single-array string values for an attribute but
          // let's support them without complaining.
          if (is_string($attributes[$attribute_name])) {
            $attributes[$attribute_name] = [$attributes[$attribute_name]];
          }
          else {
            $this->logger->warning('%name attribute is not an array of values; this points to a coding error.', [
              '%name' => $config->get('saml_attribute'),
            ]);
          }
        }
        if (is_array($attributes[$attribute_name])) {
          $separator = $config->get('saml_attribute_separator');
          foreach ($attributes[$attribute_name] as $attribute_value) {
            // "0" is a valid attribute value. "" / NULL are considered
            // 'empty / not a value' and 0 is... inconsequential.
            if ($attribute_value != NULL) {
              if (!is_string($attribute_value)) {
                $this->logger->warning('%name attribute contains a (or multiple) non-string value(s); this points to a coding error.', [
                  '%name' => $config->get('saml_attribute'),
                ]);
              }
              if ($separator) {
                $idp_role_values = array_merge($idp_role_values, explode($separator, $attribute_value));
              }
              else {
                $idp_role_values[] = $attribute_value;
              }
            }
          }
        }
      }
    }
    return $idp_role_values;
  }

  /**
   * Converts role machine names into role IDs; logs unknown names.
   *
   * @param array $role_names
   *   The role machine names to convert.
   * @param \Drupal\user\Entity\Role[] $valid_roles_by_name
   *   Array with all roles valid for this purpose.
   * @param string $config_log_name
   *   Name to use for warning log if applicable.
   *
   * @todo Likely, refactor this strange code. The role "name" == the role ID,
   *   so $valid_roles_by_name[$role_name]->id() == $role_name. We likely can
   *   just do something with array_keys($valid_roles_by_name) and don't need
   *   this separate function. (Though we still want to log unknown roles.)
   */
  protected function getRoleIds(array $role_names, array $valid_roles_by_name, $config_log_name) {
    $role_ids = [];
    foreach ($role_names as $role_name) {
      if (isset($valid_roles_by_name[$role_name])) {
        $role_ids[] = $valid_roles_by_name[$role_name]->id();
      }
      else {
        $this->logger->warning('Unknown/invalid role %role in %name configuration value; skipping part of role (un)assignment.', [
          '%name' => $config_log_name,
          '%role' => $role_name,
        ]);
      }
    }

    return $role_ids;
  }

}
