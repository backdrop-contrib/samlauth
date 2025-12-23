These are selected quick notes for developers and administrators of the settings
form.

They are considered too small for a change record. See:
* The [list of change records](https://www.drupal.org/list-changes/samlauth)
  for (more info about) larger changes.
* The [release notes on drupal.org](https://www.drupal.org/project/samlauth/releases).

8.x-3.13:

* Logging: partial revert of 3.12: samlauth_user_roles now does not log unknown
  IdP role values anymore for existing installations. This logging is now
  configurable, and enabled on new installations. To be warned when your IdP
  sends unknown roles on exising installations: configure
  `samlauth_user_roles.mapping:log_unknown` to be true.

8.x-3.12:

* Login / logout behavior:
  - The $return_to parameter to SamlService::login() / ::logout() can be a
    relative path. (This makes it more consistent with `login_redirect_url` /
    `logout_redirect_url` values.)
  - Valid RelayState parameter from IdP is always honored / cannot be
    overridden by setting a 'destination' parameter in hook_login() anymore.
    ([Change Record](https://www.drupal.org/node/3562907))

* Logging: samlauth_user_roles now logs a warning if a 'role value'
  received from the IdP cannot mapped to a Drupal role. (This was -probably-
  always intended, but never done before.)

8.x-3.11:

* Login behavior:
  - Linking to an existing already-linked user is disallowed (again, as was
    the case with externalauth < 2.0.3).
  - Fixed linking to email address for logins where name is known.

8.x-3.10:

* Added /saml/reauth route. (No further documentation provided; expected to
  only be useful for testing.)

* SamlService::getAttributes() adds the NameID sent in the login response to
  the returned value, as a single-value array keyed by the value "!nameid".
  This is not expected to cause issues because the return value already was
  overloaded / did not _just_ contain a single copy of all attribute values.

* Initial setup improvements:
  * Metadata can be generated with only SP data configured.
  * Errors are logged in case of invalid metadata XML.
  * URL argument /saml/metadata?check=0 to view metadata anyway, if invalid.

* UI: config screen split in two; "SAML communcation" vs "the rest" (including
  SAML attributes), because things were just getting too big. No config
  changes here; both screens still save to the same configuration object.

* Configurable menu titles are properly translatable using config translation.

* Configuration: Added login_link_title (string), to show link to /saml/login
  on user login form. NOTE: there was a short-lived login_link_show (boolean)
  setting that also had to be enabled, but that was removed later.

* Configuration: Added login_auto_redirect (boolean), to automatically redirect
  /user/login to the IdP always.

* Configuration: Added login_error_keep_session (boolean, default true for new
  installs) to keep SAML logout (/saml/logout) working after successful SAML
  authentication followed by Drupal login failure.

* Configuration: values for sp_private_key / sp_x509_certificate /
  sp_new_certificate / idp_cert_encryption / idp_certs (sequence of strings)
  could already contain relative paths after "file:", but the edit form
  produced an error for them. The error is fixed.

* Configuration: drupal_login_roles / map_users_roles have zero values properly
  removed when saved through the UI. There's no behavior change, but exported
  config may show differences.

* Configuration: map_users_roles are retroactively defined as "must not contain
  the values 'anonymous' or 'authenticated'" (which was never possible through
  the configuration screen) -- except the new special one-element array value
  ['anonymous'], which now means "allow all Drupal users to be linked".

8.x-3.9:

* After processing login/logout, the ACS/SLS endpoints now refuse to redirect
  to URLS deemed unsafe. New configuration bypass_relay_state_check added, to
  enable reverting to the 8.x-3.8 behavior. It will disappear in the next major
  version. If your IDP sets the RelayState to an 'external' URL in their
  login / logout responses:
  * revert the behavior using the config option / setting only if you must;
  * longer term: either be sure that the corresponding hostnames are mentioned
    in the 'trusted_host_patterns' setting (see settings.php), or code your
    own solution if that's really impossible (see
    SamlController::ensureSafeRelayState()).

8.x-3.6:

* Fixed possible information disclosure caused by hardcoded (settings.php)
  configuration being saved into active (database) config. See
  https://www.drupal.org/node/3284901 about who is vulnerable / how to fix.

8.x-3.5:

* Some more error messages are now properly translatable.

8.x-3.4:

* Configuration: security_allow_repeat_attribute_name added.

8.x-3.3:

* Configuration: security_metadata_sign, security_nameid_encrypt,
  security_nameid_encrypted, security_encryption_algorithm added. (These
  settings were added to complete the known configurable options, not to cover
  any outstanding request / issue.)

* Configuration: sp_cert_folder has been removed. sp_private_key,
  sp_x509_certificate, (new) sp_new_certificate, idp_certs and
  idp_cert_encryption can now hold values with a 'file:' and 'key:' prefix
  (followed by respectively an absolute filename and an entity ID of a 'Key'
  entity, instead of the full contents of a key/certificate).

* Configuration: idp_x509_certificate, idp_x509_certificate_multi and
  idp_cert_type have been removed. idp_certs and idp_cert_encryption have been
  added (with the idp_x509_certificate value moving to idp_certs and
  idp_x509_certificate_multi moving to either idp_certs or idp_cert_encryption).

* SamlService::$samlAuth was changed into an array. (This is not considered
  part of the interface. SamlService::getSamlAuth(), which should be used for
  getting this object, is still backward compatible.) Passing the new argument
  to getSamlAuth() is recommended if you don't want keys to be read
  unnecessarily.

8.x-3.2:

* Some SamlService::acs() code was split off into linkExistingAccount().
