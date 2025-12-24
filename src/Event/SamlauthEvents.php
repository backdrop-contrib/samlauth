<?php

namespace Drupal\samlauth\Event;

/**
 * Defines events for the samlauth module.
 *
 * @see \Drupal\samlauth\Event\SamlauthUserSyncEvent
 */
final class SamlauthEvents {

  /**
   * Name of the event fired when searching for a user to match SAML attributes.
   *
   * The event allows modules to link existing user accounts to an authname
   * through the externalauth mechanism (see externalauth module). The event
   * listener method receives a Drupal\samlauth\Event\SamlauthUserLinkEvent
   * instance. If it finds an account to link, it should call the event's
   * setLinkedAccount() method.
   *
   * @Event
   *
   * @see \Drupal\samlauth\Event\SamlauthUserLinkEvent
   *
   * @var string
   */
  const USER_LINK = 'samlauth.user_link';

  /**
   * Name of the event fired when a user is synchronized from SAML attributes.
   *
   * This includes new accounts being created for the first time (before being
   * saved; throw an exception to prevent saving of accounts).
   *
   * The event allows modules to synchronize user account values with SAML
   * attributes passed by the IdP in the authentication response. Basic required
   * properties (username, email) are already synchronized. The event listener
   * method receives a \Drupal\samlauth\Event\SamlauthUserSyncEvent instance.
   *
   * Note the distinction between the following methods on the event:
   * - isFirstLogin(): the account is new, OR already exists and is being
   *   linked during its first SAML login. See isFirstLogin() comments for
   *   caveats.
   * - getAccount()->isNew(): the account is truly new.
   * If the event subscriber makes changes to the account, it should call the
   * event's markAccountChanged() method rather than saving the account by
   * itself. This call is optional if the account is new.
   *
   * The event is fired after the SP / samlauth library validates the IdP's
   * authentication response but before the Drupal user is logged in. An event
   * subscriber may throw an exception to prevent the login.
   *
   * @Event
   *
   * @see \Drupal\samlauth\Event\SamlauthUserSyncEvent
   *
   * @var string
   */
  const USER_SYNC = 'samlauth.user_sync';

  /**
   * Name of the event fired to allow alteration of OneLogin SAML
   * library configuration.
   *
   * This event is dispatched before the OneLogin\Saml2\Auth object is
   * instantiated, allowing subscribers to modify the configuration array
   * passed to it. This enables customization of advanced SAML settings
   * not exposed in the module's configuration UI.
   *
   * The event listener method receives a
   * \Drupal\samlauth\Event\SamlauthLibraryConfigAlterEvent instance which
   * provides:
   * - getConfig(): The current configuration array
   * - setConfig(): Method to update the configuration
   * - getPurpose(): The operation context (e.g., 'login', 'acs', 'logout')
   *
   * @Event
   *
   * @see \Drupal\samlauth\Event\SamlauthLibraryConfigAlterEvent
   * @see https://github.com/SAML-Toolkits/php-saml#settings
   *
   * @var string
   */
  const LIBRARY_CONFIG_ALTER = 'samlauth.library_config_alter';

}
