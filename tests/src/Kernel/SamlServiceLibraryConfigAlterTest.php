<?php

namespace Drupal\Tests\samlauth\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\samlauth\Event\SamlauthEvents;
use Drupal\samlauth\Event\SamlauthLibraryConfigAlterEvent;
use OneLogin\Saml2\Auth;

/**
 * Tests that LIBRARY_CONFIG_ALTER event is dispatched correctly.
 *
 * @group samlauth
 */
class SamlServiceLibraryConfigAlterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'externalauth',
    'samlauth',
  ];

  /**
   * The SAML service.
   *
   * @var \Drupal\samlauth\SamlService
   */
  protected $samlService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installConfig(['samlauth']);

    // Set minimal required configuration for SamlService.
    $this->config('samlauth.authentication')
      ->set('sp_entity_id', 'test-sp-entity-id')
      ->set('idp_entity_id', 'test-idp-entity-id')
      ->set('idp_single_sign_on_service', 'https://test-idp.example.com/sso')
      ->set('idp_single_log_out_service', 'https://test-idp.example.com/slo')
      ->set('sp_x509_certificate', 'test-cert')
      ->set('sp_private_key', 'test-key')
      ->set('idp_certs', ['test-idp-cert'])
      ->save();

    $this->samlService = $this->container->get('samlauth.saml');
  }

  /**
   * Tests that the event is dispatched with correct purpose.
   */
  public function testEventDispatchedWithPurpose() {
    $event_dispatched = FALSE;
    $received_purpose = NULL;
    $received_config = NULL;

    // Subscribe to the event.
    $listener = function (SamlauthLibraryConfigAlterEvent $event) use (&$event_dispatched, &$received_purpose, &$received_config) {
      $event_dispatched = TRUE;
      $received_purpose = $event->getPurpose();
      $received_config = $event->getConfig();
    };

    $this->container->get('event_dispatcher')->addListener(
      SamlauthEvents::LIBRARY_CONFIG_ALTER,
      $listener
    );

    // Trigger the event by calling a method that uses getSamlAuth().
    // We use reflection to call the protected method.
    $reflection = new \ReflectionClass($this->samlService);
    $method = $reflection->getMethod('getSamlAuth');
    $method->setAccessible(TRUE);
    $method->invoke($this->samlService, 'login');

    $this->assertTrue($event_dispatched, 'LIBRARY_CONFIG_ALTER event should have been dispatched');
    $this->assertEquals('login', $received_purpose, 'Event must receive correct purpose');
    $this->assertIsArray($received_config, 'Event must receive config array');
    $this->assertArrayHasKey('sp', $received_config, 'Config must contain SP section');
    $this->assertArrayHasKey('idp', $received_config, 'Config must contain IdP section');
    $this->assertArrayHasKey('security', $received_config, 'Config must contain security section');
  }

  /**
   * Tests that config alterations are applied.
   */
  public function testConfigAlterationsAreApplied() {
    $test_value = 'test-custom-value-' . time();

    // Subscribe to the event and modify config.
    $listener = function (SamlauthLibraryConfigAlterEvent $event) use ($test_value) {
      if ($event->getPurpose() === 'metadata') {
        $config = $event->getConfig();
        $config['idp']['entityId'] = $test_value;
        $event->setConfig($config);
      }
    };

    $this->container->get('event_dispatcher')->addListener(
      SamlauthEvents::LIBRARY_CONFIG_ALTER,
      $listener
    );

    // Trigger the event.
    $reflection = new \ReflectionClass($this->samlService);
    $method = $reflection->getMethod('getSamlAuth');
    $method->setAccessible(TRUE);
    $auth = $method->invoke($this->samlService, 'metadata');

    // Verify the Auth object was created (meaning our config was applied).
    $this->assertInstanceOf(Auth::class, $auth);
    $this->assertEquals($test_value, $auth->getSettings()->getIdPData()['entityId'], 'IdP entityId must be altered');
  }

  /**
   * Tests event is dispatched for different purposes.
   *
   * @dataProvider purposeProvider
   */
  public function testEventDispatchedForDifferentPurposes($purpose) {
    $event_dispatched = FALSE;
    $received_purpose = NULL;

    $listener = function (SamlauthLibraryConfigAlterEvent $event) use (&$event_dispatched, &$received_purpose) {
      $event_dispatched = TRUE;
      $received_purpose = $event->getPurpose();
    };

    $this->container->get('event_dispatcher')->addListener(
      SamlauthEvents::LIBRARY_CONFIG_ALTER,
      $listener
    );

    // Trigger the event.
    $reflection = new \ReflectionClass($this->samlService);
    $method = $reflection->getMethod('getSamlAuth');
    $method->setAccessible(TRUE);
    $method->invoke($this->samlService, $purpose);

    $this->assertTrue($event_dispatched);
    $this->assertEquals($purpose, $received_purpose);
  }

  /**
   * Data provider for different purpose values.
   */
  public static function purposeProvider() {
    return [
      ['metadata'],
      ['login'],
      ['acs'],
      ['logout'],
    ];
  }

}
