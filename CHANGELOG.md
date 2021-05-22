These are selected quick notes for developers. For all changes, see the 
[release notes on drupal.org](https://www.drupal.org/project/samlauth/releases).

8.x-3.3:

* SamlService::$samlAuth was changed into an array. (This is not considered
  part of the interface. SamlService::getSamlAuth(), which should be used for
  getting this object, is still backward compatible.) Passing the new argument
  to getSamlAuth() is recommended if you don't want keys to be read 
  unnecessarily.

8.x-3.2:

* Some SamlService::acs() code was split off into linkExistingAccount().
