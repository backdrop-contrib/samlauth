<?php

namespace Drupal\samlauth;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\externalauth\ExternalAuth;
use Drupal\samlauth\Event\SamlauthEvents;
use Drupal\samlauth\Event\SamlauthUserLinkEvent;
use Drupal\samlauth\Event\SamlauthUserSyncEvent;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\user\UserInterface;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Error as SamlError;
use OneLogin\Saml2\Utils as SamlUtils;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Governs communication between the SAML toolkit and the IdP / login behavior.
 *
 * There's no formal interface here, only a promise to not change things in
 * breaking ways in the 3.x releases. The division in responsibilities between
 * this class and SamlController (which calls most of its public methods) is
 * partly arbitrary. It's roughly "Controller contains code dealing with
 * redirects; SamlService contains the other logic". Code will likely be moved
 * around to new classes in 4.x.
 */
class SamlService {
  use StringTranslationTrait;

  /**
   * An Auth object representing the current request state.
   *
   * @var \OneLogin\Saml2\Auth
   */
  protected $samlAuth;

  /**
   * The ExternalAuth service.
   *
   * @var \Drupal\externalauth\ExternalAuth
   */
  protected $externalAuth;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The EntityTypeManager service.
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
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * Private store for SAML session data.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $privateTempStore;

  /**
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new SamlService.
   *
   * @param \Drupal\externalauth\ExternalAuth $external_auth
   *   The ExternalAuth service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The EntityTypeManager service.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   A temp data store factory object.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The string translation service.
   */
  public function __construct(ExternalAuth $external_auth, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger, EventDispatcherInterface $event_dispatcher, PrivateTempStoreFactory $temp_store_factory, FloodInterface $flood, AccountInterface $current_user, MessengerInterface $messenger, TranslationInterface $translation) {
    $this->externalAuth = $external_auth;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
    $this->eventDispatcher = $event_dispatcher;
    $this->privateTempStore = $temp_store_factory->get('samlauth');
    $this->flood = $flood;
    $this->currentUser = $current_user;
    $this->messenger = $messenger;
    $this->setStringTranslation($translation);

    if ($this->configFactory->get('samlauth.authentication')->get('use_proxy_headers')) {
      // Use 'X-Forwarded-*' HTTP headers for identifying the SP URL.
      SamlUtils::setProxyVars(TRUE);
    }
  }

  /**
   * Show metadata about the local sp. Use this to configure your saml2 IdP.
   *
   * @param int|null $validity
   *   (Optional) 'validUntil' property of the metadata (which is a date, not
   *   an interval) will be this many seconds into the future. If left empty,
   *   the SAML PHP Toolkit will assign a value.
   * @param int|null $cache_duration
   *   (Optional) number of seconds used for the 'cacheDuration' property of
   *   the metadata. If left empty, the SAML PHP Toolkit will assign a value.
   *
   * @return mixed
   *   XML string representing metadata.
   *
   * @throws \OneLogin\Saml2\Error
   *   If the metatdad is invalid.
   */
  public function getMetadata($validity = NULL, $cache_duration = NULL) {
    $settings = $this->getSamlAuth()->getSettings();
    $metadata = $settings->getSPMetadata(FALSE, $validity, $cache_duration);
    $errors = $settings->validateMetadata($metadata);

    if (empty($errors)) {
      return $metadata;
    }
    else {
      throw new SamlError('Invalid SP metadata: ' . implode(', ', $errors), SamlError::METADATA_SP_INVALID);
    }
  }

  /**
   * Initiates a SAML2 authentication flow and redirects to the IdP.
   *
   * @param string $return_to
   *   (optional) The path to return the user to after successful processing by
   *   the IdP.
   * @param array $parameters
   *   (optional) Extra query parameters to add to the returned redirect URL.
   *
   * @return string
   *   The URL of the single sign-on service to redirect to, including query
   *   parameters.
   */
  public function login($return_to = NULL, array $parameters = []) {
    $config = $this->configFactory->get('samlauth.authentication');
    $url = $this->getSamlAuth()->login($return_to, $parameters, FALSE, FALSE, TRUE, $config->get('request_set_name_id_policy') ?? TRUE);
    if ($config->get('debug_log_saml_out')) {
      $this->logger->debug('Sending SAML login request: <pre>@message</pre>', ['@message' => $this->getSamlAuth()->getLastRequestXML()]);
    }
    return $url;
  }

  /**
   * Processes a SAML response (Assertion Consumer Service).
   *
   * First checks whether the SAML request is OK, then takes action on the
   * Drupal user (logs in / maps existing / create new) depending on attributes
   * sent in the request and our module configuration.
   *
   * @return bool
   *   TRUE if the response was correctly processed; FALSE if an error was
   *   encountered while processing but there's a currently logged-in user and
   *   we decided not to throw an exception for this case.
   *
   * @throws \Exception
   */
  public function acs() {
    $config = $this->configFactory->get('samlauth.authentication');
    if ($config->get('debug_log_in')) {
      if (isset($_POST['SAMLResponse'])) {
        $response = base64_decode($_POST['SAMLResponse']);
        if ($response) {
          $this->logger->debug("ACS received 'SAMLResponse' in POST request (base64 decoded): <pre>@message</pre>", ['@message' => $response]);
        }
        else {
          $this->logger->warning("ACS received 'SAMLResponse' in POST request which could not be base64 decoded: <pre>@message</pre>", ['@message' => $_POST['SAMLResponse']]);
        }
      }
      else {
        // Not sure if we should be more detailed...
        $this->logger->warning("HTTP request to ACS is not a POST request, or contains no 'SAMLResponse' parameter.");
      }
    }

    // Perform flood control. This is not to guard against failed login
    // attempts per se; that is the IdP's job. It's just protection against
    // a flood of bogus (DDoS-like) requests because this route performs
    // computationally expensive operations. So: just IP based flood control,
    // using the limit / window values that Core uses for regular login.
    $flood_config = $this->configFactory->get('user.flood');
    if (!$this->flood->isAllowed('samlauth.failed_login_ip', $flood_config->get('ip_limit'), $flood_config->get('ip_window'))) {
      throw new TooManyRequestsHttpException(NULL, 'Access is blocked because of IP based flood prevention.');
    }

    // Process the ACS response message and check if we can derive a linked
    // account, but don't process errors yet. (The following code is a kludge
    // because we may need the linked account / may ignore errors later.)
    try {
      $this->processLoginResponse();
    }
    catch (\Exception $acs_exception) {
    }
    if (!isset($acs_exception)) {
      $unique_id = $this->getAttributeByConfig('unique_id_attribute');
      if ($unique_id) {
        $account = $this->externalAuth->load($unique_id, 'samlauth') ?: NULL;
      }
    }

    $logout_different_user = $config->get('logout_different_user');
    if ($this->currentUser->isAuthenticated()) {
      // Either redirect or log out so that we can log a different user in.
      // 'Redirecting' is done by the caller - so we can just return from here.
      if (isset($account) && $account->id() === $this->currentUser->id()) {
        // Noting that we were already logged in probably isn't useful. (Core's
        // user/reset link isn't a good case to compare: it always logs the
        // user out and presents the "Reset password" form with a login button.
        // 'drush uli' links, at least on D7, display an info message "please
        // reset your password" because they land on the user edit form.)
        return !isset($acs_exception);
      }
      if (!$logout_different_user) {
        // Message similar to when a user/reset link is followed.
        $this->messenger->addWarning($this->t('Another user (%other_user) is already logged into the site on this computer, but you tried to log in as user %login_user through an external authentication provider. Please <a href=":logout">log out</a> and try again.', [
          '%other_user' => $this->currentUser->getAccountName(),
          '%login_user' => $account ? $account->getAccountName() : '?',
          // Point to /user/logout rather than /saml/logout because we don't
          // want to make people log out from all their logged-in sites.
          ':logout' => Url::fromRoute('user.logout')->toString(),
        ]));
        return !isset($acs_exception);
      }
      // If the SAML response indicates (/ if the processing generated) an
      // error, we don't want to log the current user out but we want to
      // clearly indicate that someone else is still logged in.
      if (isset($acs_exception)) {
        $this->messenger->addWarning($this->t('Another user (%other_user) is already logged into the site on this computer. You tried to log in through an external authentication provider, which failed, so the user is still logged in.', [
          '%other_user' => $this->currentUser->getAccountName(),
        ]));
      }
      else {
        $this->drupalLogoutHelper();
        $this->messenger->addStatus($this->t('Another user (%other_user) was already logged into the site on this computer, and has now been logged out.', [
          '%other_user' => $this->currentUser->getAccountName(),
        ]));
      }
    }

    if (isset($acs_exception)) {
      $this->flood->register('samlauth.failed_login_ip', $flood_config->get('ip_window'));
      throw $acs_exception;
    }
    if (!$unique_id) {
      throw new \RuntimeException('Configured unique ID is not present in SAML response.');
    }

    $this->doLogin($unique_id, $account);

    // Remember SAML session values that may be necessary for logout.
    $values = [
      'session_index' => $this->samlAuth->getSessionIndex(),
      'session_expiration' => $this->samlAuth->getSessionExpiration(),
      'name_id' => $this->samlAuth->getNameId(),
      'name_id_format' => $this->samlAuth->getNameIdFormat(),
    ];
    foreach ($values as $key => $value) {
      if (isset($value)) {
        $this->privateTempStore->set($key, $value);
      }
      else {
        $this->privateTempStore->delete($key);
      }
    }

    return TRUE;
  }

  /**
   * Processes a SAML login response; throws an exception if it isn't valid.
   *
   * The mechanics of checking whether there are any errors are not so
   * straightforward, so this helper function hopes to abstract that away.
   *
   * @todo should we also check a Response against the ID of the request we
   *   sent earlier? Seems to be not absolutely required on top of the validity
   *   / signature checks which the library already does - but every extra
   *   check is good. Maybe make it optional.
   */
  protected function processLoginResponse() {
    $config = $this->configFactory->get('samlauth.authentication');
    $auth = $this->getSamlAuth();
    // This call can throw various kinds of exceptions if the 'SAMLResponse'
    // request parameter is not present or cannot be decoded into a valid SAML
    // (XML) message, and can also set error conditions instead - if the XML
    // contains data that is not considered valid. We should likely treat all
    // error conditions the same.
    $auth->processResponse();
    if ($config->get('debug_log_saml_in')) {
      $this->logger->debug('ACS received SAML response: <pre>@message</pre>', ['@message' => $auth->getLastResponseXML()]);
    }
    $errors = $auth->getErrors();
    if ($errors) {
      // We have one or multiple error types / short descriptions, and one
      // 'reason' for the last error.
      throw new \RuntimeException('Error(s) encountered during processing of login response. Type(s): ' . implode(', ', array_unique($errors)) . '; reason given for last error: ' . $auth->getLastErrorReason());
    }
    if (!$auth->isAuthenticated()) {
      // Looking at the current code, isAuthenticated() just means "response
      // is valid" because it is mutually exclusive with $errors and exceptions
      // being thrown. So we should never get here. We're just checking it in
      // case the library code changes - in which case we should reevaluate.
      throw new \RuntimeException('SAML login response was apparently not fully validated even when no error was provided.');
    }
  }

  /**
   * Logs a user in, creating / linking an account; synchronizes attributes.
   *
   * Split off from acs() to... have at least some kind of split.
   *
   * @param string $unique_id
   *   The user account contained in the SAML response.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The user account derived from the SAML response.
   */
  protected function doLogin($unique_id, AccountInterface $account = NULL) {
    $config = $this->configFactory->get('samlauth.authentication');
    $first_saml_login = FALSE;
    if (!$account) {
      $this->logger->debug('No matching local users found for unique SAML ID @saml_id.', ['@saml_id' => $unique_id]);

      // Try to link an existing user: first through a custom event handler,
      // then by name, then by e-mail.
      if ($config->get('map_users')) {
        $event = new SamlauthUserLinkEvent($this->getAttributes());
        $this->eventDispatcher->dispatch(SamlauthEvents::USER_LINK, $event);
        $account = $event->getLinkedAccount();
        if ($account) {
          $this->logger->info('Existing user @name (@uid) was newly matched to SAML login attributes; linking user and logging in.', ['@name' => $account->getAccountName(), '@uid' => $account->id()]);
        }
        else {
          // The linking by name / e-mail cannot be bypassed at this point
          // because it makes no sense to create a new account from the SAML
          // attributes if one of these two basic properties is already in use.
          // (In this case a newly created and logged-in account would get a
          // cryptic machine name because  synchronizeUserAttributes() cannot
          // assign the proper name while saving.)
          $name = $this->getAttributeByConfig('user_name_attribute');
          if ($name && $account_search = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $name])) {
            $account = reset($account_search);
            $this->logger->info('Matching local user @uid found for name @name (as provided in a SAML attribute); linking user and logging in.', ['@name' => $name, '@uid' => $account->id()]);
          }
          else {
            $mail = $this->getAttributeByConfig('user_mail_attribute');
            if ($mail && $account_search = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $mail])) {
              $account = reset($account_search);
              $this->logger->info('Matching local user @uid found for e-mail @mail (as provided in a SAML attribute); linking user and logging in.', ['@mail' => $mail, '@uid' => $account->id()]);
            }
          }
        }
      }

      if ($account) {
        // There is a chance that the following call will not actually link the
        // account (if a mapping to this account already exists from another
        // unique ID). If that happens, it does not matter much to us; we will
        // just log the account in anyway. Next time the same not-yet-linked
        // user logs in, we will again try to link the account in the same way
        // and (falsely) log that we are linking the user.
        $this->externalAuth->linkExistingAccount($unique_id, 'samlauth', $account);
        $first_saml_login = TRUE;
      }
    }

    // If we haven't found an account to link, create one from the SAML
    // attributes.
    if (!$account) {
      if ($config->get('create_users')) {
        // The register() call will save the account. We want to:
        // - add values from the SAML response into the user account;
        // - not save the account twice (because if the second save fails we do
        //   not want to end up with a user account in an undetermined state);
        // - reuse code (i.e. call synchronizeUserAttributes() with its current
        //   signature, which is also done when an existing user logs in).
        // Because of the third point, we are not passing the necessary SAML
        // attributes into register()'s $account_data parameter, but we want to
        // hook into the save operation of the user account object that is
        // created by register(). It seems we can only do this by implementing
        // hook_user_presave() - which calls our synchronizeUserAttributes().
        $account_data = ['name' => $this->getAttributeByConfig('user_name_attribute')];
        $account = $this->externalAuth->register($unique_id, 'samlauth', $account_data);

        $this->externalAuth->userLoginFinalize($account, $unique_id, 'samlauth');
      }
      else {
        throw new UserVisibleException('No existing user account matches the SAML ID provided. This authentication service is not configured to create new accounts.');
      }
    }
    elseif ($account->isBlocked()) {
      throw new UserVisibleException('Requested account is blocked.');
    }
    else {
      // Synchronize the user account with SAML attributes if needed.
      $this->synchronizeUserAttributes($account, FALSE, $first_saml_login);

      $this->externalAuth->userLoginFinalize($account, $unique_id, 'samlauth');
    }
  }

  /**
   * Initiates a SAML2 logout flow and redirects to the IdP.
   *
   * @param string $return_to
   *   (optional) The path to return the user to after successful processing by
   *   the IdP.
   * @param array $parameters
   *   (optional) Extra query parameters to add to the returned redirect URL.
   *
   * @return string
   *   The URL of the single logout service to redirect to, including query
   *   parameters.
   */
  public function logout($return_to = NULL, array $parameters = []) {
    // Log the Drupal user out at the start of the process if they were still
    // logged in. Official SAML documentation usually specifies (as far as it
    // does) that we should log the user out after getting redirected from the
    // IdP instead, at /saml/sls. However
    // - Between calling logout() and all those redirects there is a lot that
    //   could go wrong which would then influence users' ability to log out of
    //   Drupal.
    // - There's no real downside to doing it now, either for the user or for
    //   our code (which already explicitly supports handling users who were
    //   previously logged out of Drupal).
    // - Site administrators may also want this endpoint to work for logging
    //   out non-SAML users. (Otherwise how are they going to display
    //   different login links for different users?) PLEASE NOTE however, that
    //   this is not the primary purpose of this method; it is to enable both
    //   logged-in and already-logged-out Drupal users to start a SAML logout
    //   process - i.e. to be redirected to the IdP. So a side effect is that
    //   non-SAML users are also redirected to the IdP unnecessarily. It may be
    //   possible to prevent this - but that will need to be tested carefully.
    $saml_session_data = $this->drupalLogoutHelper();

    // Start the SAML logout process. If the user was already logged out before
    // this method was called, we won't have any SAML session data so won't be
    // able to tell the IdP which session should be logging out. Even so, the
    // SAML Toolkit is able to create a generic LogoutRequest, and for at least
    // some IdPs that's enough to log the user out from the IdP if applicable
    // (because they have their own browser/cookie based session handling) and
    // return a SAMLResponse indicating success. (Maybe there's some way to
    // modify the Drupal logout process to keep the SAML session data available
    // but we won't explore that until there's a practical situation where
    // that's clearly needed.)
    // @todo should we check session expiration time before sending a logout
    //   request to the IdP? (What would an IdP do if it received an old
    //   session index? Is it better to not redirect, and throw an error on
    //   our side?)
    // @todo include nameId(SP)NameQualifier?
    $url = $this->getSamlAuth()->logout(
      $return_to,
      $parameters,
      $saml_session_data['name_id'] ?? NULL,
      $saml_session_data['session_index'] ?? NULL,
      TRUE,
      $saml_session_data['name_id_format'] ?? NULL
    );
    if ($this->configFactory->get('samlauth.authentication')->get('debug_log_saml_out')) {
      $this->logger->debug('Sending SAML logout request: <pre>@message</pre>', ['@message' => $this->getSamlAuth()->getLastRequestXML()]);
    }
    return $url;
  }

  /**
   * Does processing for the Single Logout Service.
   *
   * @return null|string
   *   Usually returns nothing. May return a URL to redirect to.
   */
  public function sls() {
    $config = $this->configFactory->get('samlauth.authentication');
    // We might at some point check if this code can be abstracted a bit...
    if ($config->get('debug_log_in')) {
      if (isset($_GET['SAMLResponse'])) {
        $response = base64_decode($_GET['SAMLResponse']);
        if ($response) {
          $this->logger->debug("SLS received 'SAMLResponse' in GET request (base64 decoded): <pre>@message</pre>", ['@message' => $response]);
        }
        else {
          $this->logger->warning("SLS received 'SAMLResponse' in GET request which could not be base64 decoded: <pre>@message</pre>", ['@message' => $_POST['SAMLResponse']]);
        }
      }
      elseif (isset($_GET['SAMLRequest'])) {
        $response = base64_decode($_GET['SAMLRequest']);
        if ($response) {
          $this->logger->debug("SLS received 'SAMLRequest' in GET request (base64 decoded): <pre>@message</pre>", ['@message' => $response]);
        }
        else {
          $this->logger->warning("SLS received 'SAMLRequest' in GET request which could not be base64 decoded: <pre>@message</pre>", ['@message' => $_POST['SAMLRequest']]);
        }
      }
      else {
        // Not sure if we should be more detailed...
        $this->logger->warning("HTTP request to SLS is not a GET request, or contains no 'SAMLResponse'/'SAMLRequest' parameters.");
      }
    }

    // Perform flood control; see acs().
    $flood_config = $this->configFactory->get('user.flood');
    if (!$this->flood->isAllowed('samlauth.failed_logout_ip', $flood_config->get('ip_limit'), $flood_config->get('ip_window'))) {
      throw new TooManyRequestsHttpException(NULL, 'Access is blocked because of IP based flood prevention.');
    }
    try {
      // Unlike the 'logout()' route, we only log the user out if we have a
      // valid request/response, so first have the SAML Toolkit check things.
      // Don't have it do any session actions, because nothing is needed
      // besides our own logout actions (if any). This call can either set an
      // error condition or throw a \OneLogin_Saml2_Error, depending on whether
      // we are processing a POST request; don't catch anything.
      // @todo should we check a LogoutResponse against the ID of the
      //   LogoutRequest we sent earlier? Seems to be not absolutely required on
      //   top of the validity / signature checks which the library already does
      //   - but every extra check is good. Maybe make it optional.
      $url = $this->getSamlAuth()->processSLO(TRUE, NULL, (bool) $config->get('security_logout_reuse_sigs'), NULL, TRUE);
    }
    catch (\Exception $e) {
      $this->flood->register('samlauth.failed_logout_ip', $flood_config->get('ip_window'));
      throw $e;
    }

    if ($config->get('debug_log_saml_in')) {
      // There should be no way we can get here if neither GET parameter is set;
      // if nothing gets logged, that's a bug.
      if (isset($_GET['SAMLResponse'])) {
        $this->logger->debug('SLS received SAML response: <pre>@message</pre>', ['@message' => $this->getSamlAuth()->getLastResponseXML()]);
      }
      elseif (isset($_GET['SAMLRequest'])) {
        $this->logger->debug('SLS received SAML request: <pre>@message</pre>', ['@message' => $this->getSamlAuth()->getLastRequestXML()]);
      }
    }
    // Now look if there were any errors and also throw.
    $errors = $this->getSamlAuth()->getErrors();
    if (!empty($errors)) {
      // We have one or multiple error types / short descriptions, and one
      // 'reason' for the last error.
      throw new \RuntimeException('Error(s) encountered during processing of SLS response. Type(s): ' . implode(', ', array_unique($errors)) . '; reason given for last error: ' . $this->getSamlAuth()->getLastErrorReason());
    }

    // Remove SAML session data, log the user out of Drupal, and return a
    // redirect URL if we got any. Usually,
    // - a LogoutRequest means we need to log out and redirect back to the IdP,
    //   for which the SAML Toolkit returned a URL.
    // - after a LogoutResponse we don't need to log out because we already did
    //   that at the start of the process, in logout() - but there's nothing
    //   against checking. We did not get an URL returned and our caller can
    //   decide what to do next.
    $this->drupalLogoutHelper();

    return $url;
  }

  /**
   * Synchronizes user data with attributes in the SAML request.
   *
   * @param \Drupal\user\UserInterface $account
   *   The Drupal user to synchronize attributes into.
   * @param bool $skip_save
   *   (optional) If TRUE, skip saving the user account.
   * @param bool $first_saml_login
   *   (optional) Indicator of whether the account is newly registered/linked.
   */
  public function synchronizeUserAttributes(UserInterface $account, $skip_save = FALSE, $first_saml_login = FALSE) {
    // Dispatch a user_sync event.
    $event = new SamlauthUserSyncEvent($account, $this->getAttributes(), $first_saml_login);
    $this->eventDispatcher->dispatch(SamlauthEvents::USER_SYNC, $event);

    if (!$skip_save && $event->isAccountChanged()) {
      $account->save();
    }
  }

  /**
   * Returns all attributes in a SAML response.
   *
   * This method will return valid data after a response is processed (i.e.
   * after samlAuth->processResponse() is called).
   *
   * @return array
   *   An array with all returned SAML attributes..
   */
  public function getAttributes() {
    $attributes = $this->getSamlAuth()->getAttributes();
    $friendly_attributes = $this->getSamlAuth()->getAttributesWithFriendlyName();

    return $attributes + $friendly_attributes;
  }

  /**
   * Returns value from a SAML attribute whose name is configured in our module.
   *
   * This method will return valid data after a response is processed (i.e.
   * after samlAuth->processResponse() is called).
   *
   * @param string $config_key
   *   A key in the module's configuration, containing the name of a SAML
   *   attribute.
   *
   * @return mixed|null
   *   The SAML attribute value; NULL if the attribute value, or configuration
   *   key, was not found.
   */
  public function getAttributeByConfig($config_key) {
    $attribute_name = $this->configFactory->get('samlauth.authentication')->get($config_key);
    if ($attribute_name) {
      $attribute = $this->getSamlAuth()->getAttribute($attribute_name);
      if (!empty($attribute[0])) {
        return $attribute[0];
      }

      $friendly_attribute = $this->getSamlAuth()->getAttributeWithFriendlyName($attribute_name);
      if (!empty($friendly_attribute[0])) {
        return $friendly_attribute[0];
      }
    }
  }

  /**
   * Returns an initialized Auth class from the SAML Toolkit.
   */
  protected function getSamlAuth() {
    if (!isset($this->samlAuth)) {
      $this->samlAuth = new Auth(static::reformatConfig($this->configFactory->get('samlauth.authentication')));
    }

    return $this->samlAuth;
  }

  /**
   * Ensures the user is logged out from Drupal; returns SAML session data.
   *
   * @param bool $delete_saml_session_data
   *   (optional) whether to delete the SAML session data. This depends on:
   *   - how bad (privacy sensitive) it is to keep around? Answer: not.
   *   - whether we expect the data to ever be reused. That is: could a SAML
   *     logout attempt be done for the same SAML session multiple times?
   *     Answer: we don't know. Unlikely, because it is not accessible anymore
   *     after logout, so the user would need to log in to Drupal locally again
   *     before anything could be done with it.
   *
   * @return array
   *   Array of data about the 'SAML session' that we stored at login. (The
   *   SAML toolkit itself does not store any data / implement the concept of a
   *   session.)
   */
  protected function drupalLogoutHelper($delete_saml_session_data = TRUE) {
    $data = [];

    if ($this->currentUser->isAuthenticated()) {
      // Get data from our temp store which is not accessible after logout.
      // DEVELOPER NOTE: It depends on our session storage, whether we want to
      // try this for unauthenticated users too. At the moment, we are sure
      // only authenticated users have any SAML session data - and trying to
      // get() a value from our privateTempStore can unnecessarily start a new
      // PHP session for unauthenticated users.
      $keys = [
        'session_index',
        'session_expiration',
        'name_id',
        'name_id_format',
      ];
      foreach ($keys as $key) {
        $data[$key] = $this->privateTempStore->get($key);
        if ($delete_saml_session_data) {
          $this->privateTempStore->delete($key);
        }
      }

      // @todo properly inject this... after #2012976 lands.
      user_logout();
    }

    return $data;
  }

  /**
   * Returns a configuration array as used by the external library.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The module configuration.
   *
   * @return array
   *   The library configuration array.
   */
  protected static function reformatConfig(ImmutableConfig $config) {
    // Check if we want to load the certificates from a folder. Either folder or
    // cert+key settings should be defined. If both are defined, "folder" is the
    // preferred method and we ignore cert/path values; we don't do more
    // complicated validation like checking whether the cert/key files exist.
    $sp_cert = '';
    $sp_key = '';
    $cert_folder = $config->get('sp_cert_folder');
    if ($cert_folder) {
      // Set the folder so the SAML toolkit knows where to look.
      if (!defined('ONELOGIN_CUSTOMPATH')) {
        define('ONELOGIN_CUSTOMPATH', "$cert_folder/");
      }
    }
    else {
      $sp_cert = $config->get('sp_x509_certificate');
      $sp_key = $config->get('sp_private_key');
    }

    $library_config = [
      'debug' => (bool) $config->get('debug_phpsaml'),
      'sp' => [
        'entityId' => $config->get('sp_entity_id'),
        'assertionConsumerService' => [
          // Try SamlController::createRedirectResponse() if curious for
          // details on why the long chained call is necessary.
          'url' => Url::fromRoute('samlauth.saml_controller_acs', [], ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
        ],
        'singleLogoutService' => [
          'url' => Url::fromRoute('samlauth.saml_controller_sls', [], ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
        ],
        'NameIDFormat' => $config->get('sp_name_id_format') ?: NULL,
        'x509cert' => $sp_cert,
        'privateKey' => $sp_key,
      ],
      'idp' => [
        'entityId' => $config->get('idp_entity_id'),
        'singleSignOnService' => [
          'url' => $config->get('idp_single_sign_on_service'),
        ],
        'singleLogoutService' => [
          'url' => $config->get('idp_single_log_out_service'),
        ],
        'x509cert' => $config->get('idp_x509_certificate'),
      ],
      'security' => [
        'authnRequestsSigned' => (bool) $config->get('security_authn_requests_sign'),
        'logoutRequestSigned' => (bool) $config->get('security_logout_requests_sign'),
        'logoutResponseSigned' => (bool) $config->get('security_logout_responses_sign'),
        'wantAssertionsEncrypted' => (bool) $config->get('security_assertions_encrypt'),
        'wantMessagesSigned' => (bool) $config->get('security_messages_sign'),
        'requestedAuthnContext' => (bool) $config->get('security_request_authn_context'),
        'lowercaseUrlencoding' => (bool) $config->get('security_lowercase_url_encoding'),
        'signatureAlgorithm' => $config->get('security_signature_algorithm'),
        // This is the first setting that is TRUE by default AND must be TRUE
        // on existing installations that didn't have the setting before, so
        // it's the first one to get a default value. (If we didn't have the
        // (bool) operator, we wouldn't necessarily need the default - but
        // leaving it out would just invite a bug later on.)
        'wantNameId' => (bool) ($config->get('security_want_name_id') ?? TRUE),
      ],
      'strict' => (bool) $config->get('strict'),
    ];

    // Check for the presence of a multi cert situation.
    $multi = $config->get('idp_cert_type');
    switch ($multi) {
      case "signing":
        $library_config['idp']['x509certMulti'] = [
          'signing' => [
            $config->get('idp_x509_certificate'),
            $config->get('idp_x509_certificate_multi'),
          ],
        ];
        break;

      case "encryption":
        $library_config['idp']['x509certMulti'] = [
          'signing' => [
            $config->get('idp_x509_certificate'),
          ],
          'encryption' => [
            $config->get('idp_x509_certificate_multi'),
          ],
        ];
        break;
    }

    return $library_config;
  }

}
