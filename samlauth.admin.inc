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
    '#description' => t('If this option is enabled, users that have a remote SAML ID will also be allowed to login through the normal Drupal process (without the intervention of the configured identity provider). Note that if Drupal login is hidden, this option will have no effect.'),
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

  $form['service_provider']['samlauth_sp_name_id_format'] = array(
    '#type' => 'textfield',
    '#title' => t('Name ID Format'),
    '#description' => t('Specify the NameIDFormat attribute to request from the identity provider'),
    '#default_value' => variable_get('samlauth_sp_name_id_format', ''),
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
        ':input[name="map_users"]' => array('checked' => FALSE),
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
        ':input[name="create_users"]' => array('checked' => FALSE),
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
        ':input[name="create_users"]' => array('checked' => FALSE),
      ),
    ),
  );

  $form['security'] = array(
    '#title' => t('Security Options'),
    '#type' => 'fieldset',
  );

  $form['security']['samlauth_security_authn_requests_sign'] = array(
    '#type' => 'checkbox',
    '#title' => t('Request signed authn requests'),
    '#default_value' => variable_get('samlauth_security_authn_requests_sign', FALSE),
  );

  $form['security']['samlauth_security_messages_sign'] = array(
    '#type' => 'checkbox',
    '#title' => t('Request messages to be signed'),
    '#default_value' => variable_get('samlauth_security_messages_sign', FALSE),
  );

  $form['security']['samlauth_security_name_id_sign'] = array(
    '#type' => 'checkbox',
    '#title' => t('Request signed NameID'),
    '#default_value' => variable_get('samlauth_security_name_id_sign', FALSE),
  );

  $form['security']['samlauth_security_request_authn_context'] = array(
    '#type' => 'checkbox',
    '#title' => t('Request authn context'),
    '#default_value' => variable_get('samlauth_security_request_authn_context', FALSE),
  );

  return system_settings_form($form);
}
