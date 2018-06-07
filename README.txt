This module provides integration between Drupal and OneLogin's SAML PHP Toolkit
(https://github.com/onelogin/php-saml/). The aim of this module is to provide an
easy to setup and simple solution to setting up a SAML Service Provider. All
configuration is handled inside Drupal and requires no editing or working with
the SAML library in order to function.

Features
--------------------------------------------------------------------------------

  * Account creation: when an unknown user logs in with the SAML system, a new
    Drupal account is automatically provisioned for them
  * Account synchronization: automatically update the username or email of a
    user based on the IdP information for the user
  * Restrict login: force SAML users to always login with SAML

Installation Notes
--------------------------------------------------------------------------------

  * The onelogin/php-saml PHP library must exist in your Drupal installation
  * Composer Manager is the recommended way to automatically handle dependencies
    for this module - see instructions below
  * The external library requires some PHP extensions to be enabled including
    php-mcrypt - Composer Manager will inform if the server does not meet the
    requirements set by the library
  * If a folder is used to store certs, place the folder outside of the Drupal
    root directory to ensure the certs are not accessible via the web server

OneLogin's SAML PHP Toolkit Library Installation
--------------------------------------------------------------------------------

  - Download Composer if you don't already have it installed:
    https://getcomposer.org/download/

  - Install Drush on your system if you haven't already:
    http://www.drush.org/en/master/

  - Download and install the Composer Manager module:
    drush en composer_manager

  - Run Composer Manager with Drush within your Drupal installation:
    drush composer-manager update --no-dev

Configuration
--------------------------------------------------------------------------------

  1. In your browser, open /admin/config/people/saml to configure the module.
  2. Configure information about the Service Provider, the IdP, user settings,
     and any additional security options.
  3. Open /saml/metadata to obtain the metadata needed to configure the IdP for
     this service provider.
