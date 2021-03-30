<?php

namespace Drupal\samlauth\Tests;

use Drupal\Tests\BrowserTestBase;
use Drupal\Component\Serialization\Yaml;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Semi random tests for the samlauth module.
 *
 * The most important part (login functionality) isn't tested yet.
 *
 * @group samlauth
 */
class SamlTest extends BrowserTestBase {

  /**
   * Modules to Enable.
   *
   * @var array
   */
  public static $modules = ['samlauth'];

  /**
   * Return info on the test.
   */
  public static function getInfo() {
    return [
      'name' => 'Tests SAML authentication',
      'description' => 'Functional tests for the samlauth module functionality.',
      'group' => 'samlauth',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    // Import testshib config.
    $config = drupal_get_path('module', 'samlauth') . '/test_resources/samlauth.authentication.yml';;
    $config = file_get_contents($config);
    $config = Yaml::decode($config);
    \Drupal::configFactory()->getEditable('samlauth.authentication')->setData($config)->save();
  }

  /**
   * Tests the Admin Page.
   */
  public function testAdminPage() {
    // Test that the administration page is present.
    // These aren't very good tests, but the form and config systems are already
    // thoroughly tested, so we're just checking the basics here.
    $web_user = $this->drupalCreateUser(['configure saml']);
    $this->drupalLogin($web_user);
    $this->drupalGet('admin/config/people/saml');
    $this->assertText('Login / Logout', 'Login / Logout fieldset present');
    $this->assertText('Service Provider', 'SP fieldset present');
    $this->assertText('Identity Provider', 'iDP fieldset present');
    $this->assertText('User Info and Syncing', 'User Info and Syncing fieldset present');
    $this->assertText('SAML Message Construction', 'SAML Message Construction fieldset present');
    $this->assertText('SAML Message Validation', 'SAML Message Validation fieldset present');
  }

  /**
   * Tests metadata coming back.
   */
  public function testMetadata() {
    $web_user = $this->drupalCreateUser(['view sp metadata']);
    $this->drupalLogin($web_user);

    // Test that we get metadata.
    $this->drupalGet('saml/metadata');
    $this->assertResponse(200, 'SP metadata is accessible');
    $this->assertRaw('entityID="samlauth"', 'Entity ID found in the metadata');
  }

  /**
   * Tests that the user is blocked from requesting a password reset in Drupal.
   */
  public function testPasswordReset() {
    $core_msg_mail_sent = version_compare(\Drupal::VERSION, '9.2.0-dev') >= 0
      ? 'an email will be sent with instructions to reset your password.'
      : 'instructions have been sent to your email address.';

    $web_user = $this->drupalCreateUser();

    $this->drupalLogin($web_user);

    // Baseline: un-linked users can still reset their password.
    $this->drupalGet('user/password');
    $this->submitForm([], 'Submit');
    $this->assertSession()->responseContains($core_msg_mail_sent);

    // Linked users cannot.
    \Drupal::service('externalauth.authmap')->save($web_user, 'samlauth', $this->randomString());
    $this->drupalGet('user/password');
    $this->submitForm([], 'Submit');
    $this->assertSession()->responseContains('This user is only allowed to log in through an external authentication provider.');
    $this->assertSession()->responseNotContains($core_msg_mail_sent);

    // ...unless they have the proper permission.
    $this->grantPermissions(Role::load(RoleInterface::AUTHENTICATED_ID), [
      'bypass saml login',
    ]);
    $this->submitForm([], 'Submit');
    $this->assertSession()->responseContains($core_msg_mail_sent);
  }

}
