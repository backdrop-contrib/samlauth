# SAML Authentication

This module provides integration between Backdrop and OneLogin's SAML PHP Toolkit
(https://github.com/onelogin/php-saml/), incuded. 

The aim of this module is to provide an easy solution to setting up a SAML Service Provider. All
configuration is handled inside Backdrop and requires no editing or working with
the SAML library in order to function.



## Features

* Account creation: when an unknown user logs in with the SAML system, a new
  Backdrop account is automatically provisioned for them
* Account synchronization: automatically update the username or email of a
  user based on the IdP information for the user
* Restrict login: force SAML users to always login with SAML


## Installation

* Install this module using the official Backdrop CMS instructions at
  https://backdropcms.org/guide/modules


## Configuration

  1. In your browser, visit /admin/config/people/saml to configure the module.
  2. Configure information about the Service Provider, the IdP, user settings,
     and any additional security options.
  3. Open /saml/metadata to obtain the metadata needed to configure the IdP for
     this service provider.


## Notes

* If creating and/or storing certificates, make sure to store them outside of the
  web root, or in a location otherwise inaccessible via the web.



## Current Maintainers

* [Richard Peacock](https://github.com/swampopus) - Originally ported to Backdrop CMS.
* Seeking additional maintainers.



## Credits

This module is based on the Drupal module samlauth-7.x-1.1

Project page: https://www.drupal.org/project/samlauth

Drupal Maintainers:

* [cweagans](https://www.drupal.org/u/cweagans)
* [roderik](https://www.drupal.org/u/roderik)
* [japerry](https://www.drupal.org/u/japerry)
* [smfsh](https://www.drupal.org/u/smfsh)

