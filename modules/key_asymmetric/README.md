## Design of key types

The general situation:

There are various standard formats for keys, which are often different for
public vs. private keys - and each format supports varying encryption
algorithms that each have their own parameters.

The standard formats are often stored as a long base64 string, which may or may
not be broken down into multiple lines for better handling, and/or have extra
info before/after this string. That info is often optional, because (in most
cases) all relevant info is encoded in the base64 string itself; the string is
often just there for clarity to humans who glance at a file containing a key.
This is not always true, however; some keys are not recognized without their
header/footer - and of some application code that is going to use the key may
not recognize it without the header/footer even though it's perfectly possible
to do so.

On top of that, that application code may be able to use older and newer key
formats - or just one of them.

**Given this situation, we've just created 'base' key types** which could
hopefully be used by as many applications as possible:

* Two different types for 'private' and 'public' - because the use cases are by
  definition so different that you always know which one you want to use.
* The key will accept any value / format (also non-base64-encoded) with these
  limitations:
  * The PHP Secure Communications Library ([phpseclib](https://phpseclib.com/))
    is used for validating the key value. If it can be validated as being a
    key, some metadata is extracted and stored in the key type settings, for
    easier access by Drupal code (that doesn't need to fetch and interpret the
    key value itself, in order to know what kind of key/cert this is). This
    metadata equals the return value of key_asymmetric_get_key_properties().
  * This means we fully depend on this library to recognize all kinds of keys.
    In general this won't be an issue because the library is quite mature.
  * There is an option in the UI to not validate the value, in which these
    setting values won't be available.
* The 'public' key can contain just a public key or a X.509 certificate (that
  also contains a public key).
* The 'public' key settings contain an optional reference to a private key, so
  keypairs can be recognized by the applications that need this. This setting
  (named 'private_key') is the one thing editable through the configuration UI,
  and is merged/saved together with the metadata.

This is a separate submodule just because of its custom dependency on an
external library.


## Use cases

The initial reason for creating these private/public key types was to store a
private/public keypair that is needed by an external library. (It's beneficial
to have the private key safely stored. It's not necessary for the public
key/cert but it is useful to have both stored in the same way.)

Other uses include encryption (for which you'd normally use the public key so
someone else can decrypt things with the private key), decryption (the reverse,
using the private key) and 'signing' data (using the private key) inside your
Drupal application. See https://www.drupal.org/project/encrypt for a list of
modules that can perform encryption/decryption based on keys, though at this
moment none would work out of the box using our keys:
* encrypt_rsa has plugins that perform AES encryption / decryption using the
  (stable/modern) openssl extension, and the (older) easyrsa library. It also
  includes its own key plugins for public/private keys so you wouldn't need
  this submodule for that - but if you like these key type plugins better, it's
  probably a one-line change to support them.
* sodium's plugin uses the stable/modern sodium library and at this moment only
  does symmetric encryption; it could be augmented to support asymmetric.
* encrypt_seclib module does the same using an older version of the phpseclib
  library which we use.

If your application code only uses specific key formats (which is likely) or
encryption methods / key sizes / etc, you can do several things:
* Just leave it up to the site users to enter the right kinds of keys - and
  have your application error out if a key is unrecognized.
* If your code is in charge of selecting which keys (that are already present
  in the Drupal application) are going to be used, change the selection
  mechanism to exclude the ones that don't have the desired key type settings:
  ```php
    $all_keys = \Drupal::service('key.repository')->getKeysByType('asymmetric_public');
    $suitable_keys = [];
    foreach ($all_keys as $key) {
      // Filter based on properties stored in the 'key type settings', i.e. the
      // configuration for the Key Type plugin.
      $key_type_settings = $key->getKeyType()->getConfiguration();
      if (in_array($key_type_settings['format'] ?? NULL, ['PKCS1', 'PKCS8'], TRUE)) {
        // There is one setting that is configured through the UI: whether a
        // public key has an attached private key.
        if (!empty($key_type_settings['private_key'])) {
          $suitable_keys[] = $key;
        }
      }
    }
  ```
  You cannot be absolutely sure that noone has tampered with the metadata, so
  if you need that absolute certainty, you'll need to check the key itself
  before using it. (See key_asymmetric_get_key_properties().)
* Implement a custom key type that extends AsymmetricPrivateKeyType /
  AsymmetricPublicKeyType, remove the 'skip_validation' checkbox and change
  validateKeyValue() to set an error if the key doesn't meet your criteria.
  Then use that custom key type.

### Formatting

These key types do not reformat the provided value, and it is up to the
application code to take care of this if needed. As an example: PKCS#8 keys
/ certificates are recognized by phpseclib (and therefore stored by us with
applicable metadata) both with and without PEM formatting ('----- BEGIN /
----- END' armor and/or newlines). Keys (though apparently not certificates)
are also recognized regardless whether their value is base64 encoded. So
* If your code requires a base64 encoded value: check if it is currently not
  base64 encoded (by e.g. calling `base64_decode(..., TRUE)`) and encode it
  yourself if not.
* If your code requires 'PEM armor': check for it, and add it yourself if not
  present. (For example, by load*()ing the key value in a similar manner as
  key_asymmetric_get_key_properties() does, and casting the return value to a
  string.)

If you think general helper code is necessary inside this module: feel free to
open an issue and send in a patch.

(Having the Key module itself take care of this reformatting, means that all
Key Providers should be able to reformat the key value in this manner - which
would require an architectural change.)
