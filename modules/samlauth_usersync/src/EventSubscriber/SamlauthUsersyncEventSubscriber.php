<?php

namespace Drupal\samlauth_usersync\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\samlauth\Event\SamlauthEvents;
use Drupal\samlauth\Event\SamlauthUserLinkEvent;
use Drupal\samlauth\Event\SamlauthUserSyncEvent;
use Drupal\samlauth\SamlService;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for the samlauth_usersync module.
 */
class SamlauthUsersyncEventSubscriber implements EventSubscriberInterface {

  /**
   * The general samlauth SAML service.
   *
   * @var \Drupal\samlauth\SamlService
   */
  protected $saml;

  /**
   * A configuration object containing samlauth user settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $userSettings;

  /**
   * A configuration object containing samlauth settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $userMapping;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new SamlauthUsersyncEventSubscriber.
   *
   * @param \Drupal\samlauth\SamlService $saml
   *   The samlauth SAML service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The EntityTypeManager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(SamlService $saml, EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory) {
    $this->saml = $saml;
    $this->entityTypeManager = $entity_type_manager;
    $this->userMapping = $config_factory->get('samlauth_usersync.mapping');
    $this->userSettings = $config_factory->get('samlauth_usersync.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[SamlauthEvents::USER_LINK][] = array('linkUser');
    $events[SamlauthEvents::USER_SYNC][] = array('syncUser');
    return $events;
  }

  /**
   * Tries to link an existing non-linked user to the given SAML attribute set.
   *
   * @param \Drupal\samlauth\Event\SamlauthUserLinkEvent $event
   *   The subscribed event.
   */
  public function linkUser(SamlauthUserLinkEvent $event) {
    $user = $this->loadAccountByAttributes($this->saml->getAttributes());
    if ($user) {
      $event->setLinkedAccount($user);
    }
  }

  /**
   * Synchronizes the user with SAML attributes.
   *
   * @param \Drupal\samlauth\Event\SamlauthUserSyncEvent $event
   *   The subscribed event.
   */
  public function syncUser(SamlauthUserSyncEvent $event) {
    $role_names = [];
    $account = $event->getAccount();
    $attributes = $this->saml->getAttributes();

    foreach ($this->userMapping() as $field_name => $mapping) {
      // Assign a value from the SAML response if it's present, otherwise skip.
      if (!empty($attributes[$mapping['attribute']])) {

        if ($field_name === 'roles') {
          $role_names = $attributes[$mapping['attribute']];
        }
        else {
          // @todo check existing value
          // Set the field. The attribute value is (probably) a one-element
          // array; the user entity's magic setter method automatically takes
          // care of this, when all fields mapped here are known FieldAPI
          // fields. (The configuration form only allows setting known FieldAPI
          // fields so we should be safe.)
          $existing_value = $account->get($field_name);
          $account->set($field_name, $attributes[$mapping['attribute']]);
          // Get the value we just set, for comparison (so we are independent of
          // setter/getter specifics).
          if ($account->get($field_name) != $existing_value) {
            $event->markAccountChanged();
          }
        }
      }
    }

    // Re-add all roles. (Start empty.)
    $existing_roles = $account->getRoles();
    foreach ($existing_roles as $rid) {
      $account->removeRole($rid);
    }
    $this->addAccountRoles($account, $role_names);
    // Compare old and new value.
    sort($existing_roles);
    $new_roles  = $account->getRoles();
    sort($new_roles);
    if ($new_roles != $existing_roles) {
      $event->markAccountChanged();
    }
  }

  /**
   * Load user account by SAML assertion attributes.
   *
   * This method tries its best to find a Drupal user account based on the SAML
   * assertion attributes. If more then one account is found then no account
   * will be linked, instead FALSE will be returned.
   *
   * @param array $attributes
   *   An array of SAML assertion attributes.
   *
   * @return \Drupal\user\UserInterface|false
   *   A Drupal user object; otherwise FALSE.
   *
   * @throws \RuntimeException
   *   If multiple users are found that could be linked.
   */
  protected function loadAccountByAttributes(array $attributes) {
    if (empty($attributes)) {
      return FALSE;
    }
    $user_mapping = $this->userMapping();
    if (empty($user_mapping)) {
      return FALSE;
    }
    $conjunction = $this->userSettings->get('account.linking.conjunction');

    $user_query = $this->query($conjunction);
    foreach ($user_mapping as $field_name => $mapping) {
      if (!isset($attributes[$mapping['attribute']])
        || !$mapping['settings']['use_account_linking']) {
        continue;
      }
      // The value is (probably) a one-element array, but this still generates a
      // proper '=' condition.
      $user_query->condition($field_name, $attributes[$mapping['attribute']]);
    }
    $results = $user_query->execute();

    $count = count($results);
    if (!$count) {
      return FALSE;
    }
    if ($count > 1) {
      // If we don't want to choose the wrong user, and we also don't want to
      // create yet another user, we have no option but to throw an exception
      // (which will abort login) and let the user figure it out.
      throw new RuntimeException(count($results) . ' existing Drupal users can be linked to the user authenticated by the SAML IDP. This should be fixed before the user can log in.');
    }
    $user_id = reset($results);

    return User::load($user_id);
  }

  /**
   * Query user account object.
   *
   * @param string $conjunction
   *   (optional) The logical operator for the query, either:
   *   - AND: all of the conditions on the query need to match.
   *   - OR: at least one of the conditions on the query need to match.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The query object.
   */
  protected function query($conjunction) {
    return $this->entityTypeManager->getStorage('user')->getQuery($conjunction);
  }

  /**
   * Retrieve available user mappings that have attributes referenced.
   *
   * @return array
   *   An array of user mappings.
   */
  protected function userMapping() {
    $user_mapping = [];

    if ($user_mappings = $this->userMapping->get('user_mapping')) {
      foreach ($user_mappings as $field_name => $mapping) {
        if (empty($mapping['attribute'])) {
          continue;
        }

        $user_mapping[$field_name] = $mapping;
      }
    }

    return $user_mapping;
  }

  /**
   * Add roles to account from SAML response and configuration value.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account to assign roles to.
   * @param string[] $role_names
   *   The names of roles to assign, from the SAML response.
   */
  protected function addAccountRoles(UserInterface $account, array $role_names) {
    // Assign roles from the configuration value.
    if ($assigned_role = $this->userMapping->get('user_roles.assigned_role')) {
      foreach (array_keys(array_filter($assigned_role)) as $role_id) {
        if ($account->hasRole($role_id)) {
          continue;
        }
        $account->addRole($role_id);
      }
    }

    // @todo assignAccountRolesByName, or something. This should add to, not
    // overwrite, the roles. ALSO: put explanation on the config form.
  }

}
