<?php

/**
 * @file
 * Administrative pages for the SAML Authentication module.
 */

/**
 * Form builder for samlauth_configure_form.
 */
function samlauth_configure_form() {
  if (!class_exists('\OneLogin\Saml2\Auth')) {
    drupal_set_message('The SAML PHP Toolkit does not appear to be installed and this module will not work without it. Please consult the README for information on installation.', 'error');
  }

  $config_array = samlauth_get_config();

  $form['saml_requirements'] = array(
    '#type' => 'fieldset',
    '#title' => t('SAML Requirements'),
  );

  $form['saml_requirements']['samlauth_drupal_saml_login'] = array(
    '#type' => 'checkbox',
    '#title' => t('Allow SAML users to login directly with Drupal'),
    '#description' => t('Drupal users who have previously logged in through the SAML Identity Provider can also log in through the standard Drupal login screen. (By default, they must always log in through the Identity Provider. This option does not affect Drupal user acounts that are never linked to a SAML login.)'),
    '#default_value' => variable_get('samlauth_drupal_saml_login', FALSE),
  );

  $form['service_provider'] = array(
    '#type' => 'fieldset',
    '#title' => t('Service Provider Configuration'),
  );

  $form['service_provider']['samlauth_config_info'] = array(
    '#theme' => 'item_list',
    '#items' => array(
      t('Metadata URL') . ': ' . l(url('/saml/metadata', array('absolute' => TRUE)), '/saml/metadata'),
      t('Assertion Consumer Service') . ': ' . $config_array['sp']['assertionConsumerService']['url'],
      t('Single Logout Service') . ': ' . $config_array['sp']['singleLogoutService']['url'],
    ),
    '#empty' => array(),
    '#list_type' => 'ul',
  );

  $form['service_provider']['samlauth_sp_entity_id'] = array(
    '#type' => 'textfield',
    '#title' => t('Entity ID'),
    '#description' => t('Specifies the identifier to be used to represent the SP.'),
    '#default_value' => variable_get('samlauth_sp_entity_id', ''),
  );

  $form['service_provider']['samlauth_sp_x509_certificate'] = array(
    '#type' => 'textarea',
    '#title' => t('x509 Certificate'),
    '#description' => t('Public x509 certificate of the SP. No line breaks or BEGIN CERTIFICATE or END CERTIFICATE lines.'),
    '#default_value' => variable_get('samlauth_sp_x509_certificate', ''),
  );

  $form['service_provider']['samlauth_sp_private_key'] = array(
    '#type' => 'textarea',
    '#title' => t('Private Key'),
    '#description' => t('Private key for SP. No line breaks or BEGIN CERTIFICATE or END CERTIFICATE lines.'),
    '#default_value' => variable_get('samlauth_sp_private_key', ''),
  );

  $form['identity_provider'] = array(
    '#type' => 'fieldset',
    '#title' => t('Identity Provider Configuration'),
  );

  // @TODO: Allow a user to automagically populate this by providing a metadata URL for the iDP.
  //    $form['identity_provider']['samlauth_idp_metadata_url'] = array(
  //      '#type' => 'textfield',
  //      '#title' => t('Metadata URL'),
  //      '#description' => t('URL of the XML metadata for the identity provider'),
  //      '#default_value' => variable_get('samlauth_idp_metadata_url'),
  //    );

  $form['identity_provider']['samlauth_idp_entity_id'] = array(
    '#type' => 'textfield',
    '#title' => t('Entity ID'),
    '#description' => t('Specifies the identifier to be used to represent the IDP.'),
    '#default_value' => variable_get('samlauth_idp_entity_id', ''),
  );

  $form['identity_provider']['samlauth_idp_single_sign_on_service'] = array(
    '#type' => 'textfield',
    '#title' => t('Single Sign On Service'),
    '#description' => t('URL where the SP will send the authentication request message.'),
    '#default_value' => variable_get('samlauth_idp_single_sign_on_service', ''),
  );

  $form['identity_provider']['samlauth_idp_single_log_out_service'] = array(
    '#type' => 'textfield',
    '#title' => t('Single Log Out Service'),
    '#description' => t('URL where the SP will send the logout request message.'),
    '#default_value' => variable_get('samlauth_idp_single_log_out_service', ''),
  );

  $form['identity_provider']['samlauth_idp_change_password_service'] = array(
    '#type' => 'textfield',
    '#title' => t('Change Password Service'),
    '#description' => t('URL where users will be redirected to change their password.'),
    '#default_value' => variable_get('samlauth_idp_change_password_service', ''),
  );

  $form['identity_provider']['samlauth_idp_x509_certificate'] = array(
    '#type' => 'textarea',
    '#title' => t('x509 Certificate'),
    '#description' => t('Public x509 certificate of the IdP'),
    '#default_value' => variable_get('samlauth_idp_x509_certificate', ''),
  );

  $form['user_info'] = array(
    '#title' => t('User Info and Syncing'),
    '#type' => 'fieldset',
  );

  $form['user_info']['samlauth_unique_id_attribute'] = array(
    '#type' => 'textfield',
    '#title' => t('Unique identifier attribute'),
    '#description' => t('Specify a SAML attribute that is always going to be unique on a per-user basis. This will be used to identify local users (and create new ones if the option is enabled.<br />Example: <em>eduPersonPrincipalName</em> or <em>eduPersonTargetedID</em>'),
    '#default_value' => variable_get('samlauth_unique_id_attribute', 'eduPersonTargetedID'),
  );

  $form['user_info']['samlauth_map_users'] = array(
    '#type' => 'checkbox',
    '#title' => t('Attempt to map SAML users to existing local users?'),
    '#description' => t('If this option is enabled and a SAML authentication response is received for a user that already exists locally, and the user\'s email matches the configured attribute, the SAML user will be mapped to the local user and then logged in.'),
    '#default_value' => variable_get('samlauth_map_users', FALSE),
  );

  $form['user_info']['samlauth_map_users_email'] = array(
    '#type' => 'textfield',
    '#title' => t('Email attribute (for mapping)'),
    '#description' => t('This attribute will be used for mapping SAML users to local Drupal users.'),
    '#default_value' => variable_get('samlauth_map_users_email', FALSE),
    '#states' => array(
      'invisible' => array(
        ':input[name="samlauth_map_users"]' => array('checked' => FALSE),
      ),
    ),
  );

  $form['user_info']['samlauth_create_users'] = array(
    '#type' => 'checkbox',
    '#title' => t('Create users specified by SAML server?'),
    '#description' => t('If this option is enabled, users that do not exist in the Drupal database will be created if specified by a successful SAML authentication response.'),
    '#default_value' => variable_get('samlauth_create_users', FALSE),
  );

  $form['user_info']['samlauth_user_name_attribute'] = array(
    '#type' => 'textfield',
    '#title' => t('User name attribute'),
    '#description' => t('When SAML users are created, this field specifies which SAML attribute should be used for the Drupal user name.<br />Example: <em>cn</em> or <em>eduPersonPrincipalName</em>'),
    '#default_value' => variable_get('samlauth_user_name_attribute', 'cn'),
    '#states' => array(
      'invisible' => array(
        ':input[name="samlauth_create_users"]' => array('checked' => FALSE),
      ),
    ),
  );

  $form['user_info']['samlauth_user_mail_attribute'] = array(
    '#type' => 'textfield',
    '#title' => t('User email attribute'),
    '#description' => t('When SAML users are created, this field specifies which SAML attribute should be used for the Drupal email address.<br />Example: <em>mail</em>'),
    '#default_value' => variable_get('samlauth_user_mail_attribute', 'email'),
    '#states' => array(
      'invisible' => array(
        ':input[name="samlauth_create_users"]' => array('checked' => FALSE),
      ),
    ),
  );

  $form['construction'] = array(
    '#title' => t('SAML Message Construction'),
    '#type' => 'fieldset',
  );

  $form['construction']['samlauth_security_authn_requests_sign'] = array(
    '#type' => 'checkbox',
    '#title' => t('Sign authentication requests'),
    '#description' => t('Requests sent to the Single Sign-On Service of the IdP will include a signature.'),
    '#default_value' => variable_get('samlauth_security_authn_requests_sign', FALSE),
  );

  $form['construction']['samlauth_logout_requests_sign'] = array(
    '#type' => 'checkbox',
    '#title' => t('Sign logout requests'),
    '#description' => t('Requests sent to the Single Logout Service of the IdP will include a signature.'),
    '#default_value' => variable_get('samlauth_logout_requests_sign', FALSE),
  );

  $form['construction']['samlauth_logout_responses_sign'] = array(
    '#type' => 'checkbox',
    '#title' => t('Sign logout responses'),
    '#description' => t('Responses sent back to the IdP will include a signature.'),
    '#default_value' => variable_get('samlauth_logout_responses_sign', FALSE),
  );

  $form['construction']['samlauth_security_security_signature_algorithm'] = array(
    '#type' => 'select',
    '#title' => t('Signature algorithm'),
    '#options' => array(
      '' => t('library default'),
      'http://www.w3.org/2000/09/xmldsig#rsa-sha1' => 'RSA-sha1',
      'http://www.w3.org/2000/09/xmldsig#hmac-sha1' => 'HMAC-sha1',
      'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' => 'sha256',
      'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384' => 'sha384',
      'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512' => 'sha512'
    ),
    '#description' => t('Algorithm used in the signing process.'),
    '#default_value' => variable_get('samlauth_security_security_signature_algorithm'),
    '#states' => array(
      'invisible' => array(
        ':input[name="samlauth_security_authn_requests_sign"]' => array('checked' => FALSE),
        ':input[name="samlauth_logout_requests_sign"]' => array('checked' => FALSE),
        ':input[name="samlauth_logout_responses_sign"]' => array('checked' => FALSE),
      ),
    ),
  );

  $form['construction']['samlauth_security_request_authn_context'] = array(
    '#type' => 'checkbox',
    '#title' => t('Specify authentication context'),
    '#description' => t('Specify that only a subset of authentication methods available at the IdP should be used. (If checked, the "PasswordProtectedTransport" authentication method is specified, which is default behavior for the SAML Toolkit library. If other restrictions are needed, we should change the checkbox to a text input.)'),
    '#default_value' => variable_get('samlauth_security_request_authn_context', FALSE),
  );

  $form['construction']['samlauth_set_nameidpolicy'] = array(
    '#type' => 'checkbox',
    '#title' => t('Specify NameID policy'),
    '#description' => t('A NameIDPolicy element is added in authentication requests. This is default behavior for the SAML Toolkit library, but may be unneeded. If checked, "NameID Format" may need to be specified too. If unchecked, the "Require NameID" checkbox may need to be unchecked too.'),
    '#default_value' => variable_get('samlauth_set_nameidpolicy', TRUE),
  );

  $form['construction']['samlauth_sp_name_id_format'] = array(
    '#type' => 'textfield',
    '#title' => t('Name ID Format'),
    '#description' => t('The format for the NameID attribute to request from the identity provider. If "Specify NameID policy" is unchecked, this value is not included in authentication requests but is still included in the SP metadata. Some common formats (with "unspecified" being the default):<br>urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified<br>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress<br>urn:oasis:names:tc:SAML:2.0:nameid-format:transient<br>urn:oasis:names:tc:SAML:2.0:nameid-format:persistent'),
    '#default_value' => variable_get('samlauth_sp_name_id_format', ''),
  );

  $form['validation'] = array(
    '#title' => t('SAML Message Validation'),
    '#type' => 'fieldset',
  );

  $form['validation']['samlauth_want_name_id'] = array(
    '#type' => 'checkbox',
    '#title' => t('Require NameID'),
    '#description' => t('The login response from the IdP must contain a NameID attribute. (This is default behavior for the SAML Toolkit library, but the SAML Authentication module does not use NameID values, so it seems this can be unchecked safely.)'),
    '#default_value' => variable_get('samlauth_want_name_id', TRUE),
  );

  // This option's default value is FALSE but according to the SAML spec,
  // signing parameters should always be retrieved from the original request
  // instead of recalculated. (As argued in e.g.
  // https://github.com/onelogin/php-saml/issues/130.) The 'TRUE' option
  // (which was implemented in #6a828bf, as a result of
  // https://github.com/onelogin/php-saml/pull/37) reads the parameters from
  // $_SERVER['REQUEST'] but unfortunately this is not always populated in
  // all PHP/webserver configurations. IMHO the code should have a fallback
  // to other 'self encoding' methods if $_SERVER['REQUEST'] is empty; I see
  // no downside to that and it would enable us to always set TRUE / get rid
  // of this option in a future version of the SAML Toolkit library.
  // @todo file PR against SAML toolkit; note it in https://www.drupal.org/project/samlauth/issues/3131028
  $form['validation']['samlauth_logout_reuse_sigs'] = array(
    '#type' => 'checkbox',
    '#title' => t("Retrieve logout signature parameters from \$_SERVER['REQUEST']"),
    '#description' => t('Validation of logout requests/responses can fail on some IdPs (among others, ADFS) if this option is not set. This happens independently of the  "Strict validation" option.'),
    '#default_value' => variable_get('samlauth_logout_reuse_sigs'),
  );

  $form['validation']['samlauth_security_strict'] = array(
    '#type' => 'checkbox',
    '#title' => t('Strict mode'),
    '#description' => t('In strict mode, any validation failures or unsigned SAML messages which are requested to be signed (according to your settings) will cause the SAML conversation to be terminated. In production environments, this <em>must</em> be set.'),
    '#default_value' => variable_get('samlauth_security_strict', FALSE),
  );

  $form['validation']['samlauth_security_messages_sign'] = array(
    '#type' => 'checkbox',
    '#title' => t('Require messages to be signed'),
    '#description' => t('Responses (and logout requests) from the IdP are expected to be signed.'),
    '#default_value' => variable_get('samlauth_security_messages_sign', FALSE),
    '#states' => array(
      'disabled' => array(
        ':input[name="samlauth_security_strict"]' => array('checked' => FALSE),
      ),
    ),
  );

  $form['validation']['samlauth_security_assertions_signed'] = array(
    '#type' => 'checkbox',
    '#title' => t('Require assertions to be signed'),
    '#description' => t('Assertion elements in login responses from the IdP are expected to be signed. (When strict validation is turned off, this check is not performed but the expectation is still specified in the SP metadata.)'),
    '#default_value' => variable_get('samlauth_security_assertions_signed', FALSE),
  );

  $form['validation']['samlauth_security_assertions_encrypted'] = array(
    '#type' => 'checkbox',
    '#title' => t('Require assertions to be encrypted'),
    '#description' => t('Assertion elements in responses from the IdP are expected to be encrypted. (When strict validation is turned off, this check is not performed but the expectation is still specified in the SP metadata.)'),
    '#default_value' => variable_get('samlauth_security_assertions_encrypted', FALSE),
  );

  $form['other'] = array(
    '#title' => t('Other'),
    '#type' => 'fieldset',
  );

  // This option has effect on signing of (login + logout) requests and
  // logout responses. It's badly named (in the SAML Toolkit;
  // "lowercaseUrlencoding") because there has never been any connection to
  // the case of URL-encoded strings. The only thing this does is use
  // rawurlencode() rather than urlencode() for URL encoding of signatures
  // sent to the IdP. This option arguably shouldn't even exist because the
  // use of urlencode() arguably is a bug that should just have been fixed.
  // (The name "lowercaseUrlencoding" seems to come from a mistake: it
  // originates from https://github.com/onelogin/python-saml/pull/144/files,
  // a PR for the signature validation code for incoming messages, which was
  // then mentioned in https://github.com/onelogin/php-saml/issues/136.
  // However, the latter / this option is about signature generation for
  // outgoing messages. Validation concerns different code, and is influenced
  // by the 'security_logout_reuse_sigs' option below, which has its own
  // issues.) This means that the default value should actually be TRUE.
  // @todo file PR against SAML toolkit; note it in https://www.drupal.org/project/samlauth/issues/3131028
  // @TODO change default to TRUE; amend description (and d.o issue, and README
  $form['other']['samlauth_lowercase_url_encoding'] = array(
    '#type' => 'checkbox',
    '#title' => t("'Raw' encode signatures when signing messages"),
    '#description' => t("If there is ever a reason to turn this option off, a bug report is greatly appreciated. (The module author believes this option is unnecessary and plans for a PR to the SAML Toolkit to re-document it / phase it out.)"),
    // This default value means it will get turned on (when it wasn't before)
    // whenever someone on 7.x-1.0+ re-saves the config screen. I'm taking the
    // risk with an educated guess that noone will ever be affected by this.
    // (People with an ADFS IdP can be affected by it being FALSE, though.)
    '#default_value' => variable_get('samlauth_lowercase_url_encoding', TRUE),
    '#states' => array(
      'disabled' => array(
        ':input[name="security_authn_requests_sign"]' => array('checked' => FALSE),
        ':input[name="security_logout_requests_sign"]' => array('checked' => FALSE),
        ':input[name="security_logout_responses_sign"]' => array('checked' => FALSE),
      ),
    ),
  );

  return system_settings_form($form);
}
