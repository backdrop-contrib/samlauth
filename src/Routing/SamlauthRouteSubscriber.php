<?php

namespace Drupal\samlauth\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Listens to the dynamic route events.
 */
class SamlauthRouteSubscriber extends RouteSubscriberBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new SamlauthRouteSubscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    $config = $this->configFactory->get('samlauth.authentication');
    $custom_routing_enabled = $config->get('custom_routing_enabled');
    $custom_routing_path = $config->get('custom_routing_path');

    if ($custom_routing_enabled && !empty($custom_routing_path)) {
      $saml_routes = [
        'samlauth.saml_controller_login',
        'samlauth.saml_controller_reauth',
        'samlauth.saml_controller_logout',
        'samlauth.saml_controller_metadata',
        'samlauth.saml_controller_acs',
        'samlauth.saml_controller_sls',
        'samlauth.saml_controller_changepw',
      ];

      foreach ($saml_routes as $route_name) {
        if ($route = $collection->get($route_name)) {
          $original_path = $route->getPath();
          // Replace '/saml/' with the custom path.
          $new_path = str_replace('/saml/', rtrim($custom_routing_path, '/') . '/', $original_path);
          $route->setPath($new_path);
        }
      }
    }
  }

}