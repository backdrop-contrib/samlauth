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
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for the samlauth module.
 *
 * @todo after we know what we want with this code, clean up the service's
 *   depencencies (and possibly split over two event subscribers) / rename.
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
   * Constructs a new DynamicPageCacheSubscriber object.
   *
   * @param \Drupal\samlauth\SamlService $saml
   *   The samlauth SAML service.
   *
   * @todo add here
   */
  public function __construct(SamlService $saml,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config
  ) {
    $this->saml = $saml;
    $this->entityTypeManager = $entity_type_manager;
    $this->userMapping = $config->get('samlauth.user.mapping');
    $this->userSettings = $config->get('samlauth.user.settings');
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
   * Tries to link an existing non-linked user to the given authname.
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
    $account = $event->getAccount();

    $this->assignAccountRoles($account);

    $attributes = $this->saml->getAttributes();
    foreach ($this->userMapping() as $field_name => $mapping) {
      if (!isset($attributes[$mapping['attribute']])
        || empty($attributes[$mapping['attribute']])) {
        continue;
      }

      if ($field_name === 'roles') {
        // @todo assignAccountRolesByName, or something. This should add to, not
        // overwrite, the roles. ALSO: put explanation on the config form.
      }
      else {
        // @todo check existing value
        // Set the field. The value is (probably) a one-element array; the user
        // entity's magic setter method automatically takes care of this, when
        // all fields mapped here are known FieldAPI fields. (The configuration
        // form only allows setting known FieldAPI fields so we should be safe.)
        $account->$field_name = $attributes[$mapping['attribute']];
        // @todo - $event->markAccountChanged();
        //   Until we do this, syncing values to non-new accounts won't work.
      }
    }

  }

  /**
   * Load user account by SAML assertion attributes.
   *
   * This method tries its best to find a Drupal user account based on the SAML
   * assertion attributes. If more then one account is found then no account
   * will be linked, instead FALSE will be returned.
   *
   * @todo throw exception instead, at duplicate users?
   *
   * @param array $attributes
   *   An array of SAML assertion attributes.
   *
   * @return \Drupal\user\UserInterface|bool
   *   A Drupal user object; otherwise FALSE.
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

    $user_query = $this->queryUser($conjunction);
    foreach ($user_mapping as $field_name => $mapping) {
      if (!isset($attributes[$mapping['attribute']])
        || !$mapping['settings']['use_account_linking']) {
        continue;
      }
      // The value is (probably) a one-element array, but this still generates
      // a proper '=' condition.
      $user_query->condition($field_name, $attributes[$mapping['attribute']]); // @TODO this is array, should not be.
    }
    $results = $user_query->execute();

    if (count($results) !== 1) {
      return FALSE;
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
  protected function queryUser($conjunction) {
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
   * Assign account configured roles to current account.
   *
   * @param \Drupal\user\UserInterface $account
   *   TRUE if you would like to save roles to account; Otherwise FALSE.
   */
  protected function assignAccountRoles(UserInterface $account) {
    if ($assigned_role = $this->userMapping->get('user_roles.assigned_role')) {
      foreach (array_keys(array_filter($assigned_role)) as $role_id) {
        if ($account->hasRole($role_id)) {
          continue;
        }
        $account->addRole($role_id);
      }
    }
  }

}
