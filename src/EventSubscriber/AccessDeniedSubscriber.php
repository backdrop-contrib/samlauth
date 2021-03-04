<?php

namespace Drupal\samlauth\EventSubscriber;

use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects logged-in users when access is denied to /saml/login.
 */
class AccessDeniedSubscriber implements EventSubscriberInterface {

  /**
   * Routes to check.
   *
   * @var array
   */
  const INTERNALROUTES = [
    'samlauth.saml_controller_login',
    'samlauth.saml_controller_acs',
  ];

  /**
   * Routes which can throw TooManyRequestsHttpException.
   *
   * @var array
   */
  const FLOOD_CONTROL_ROUTES = [
    'samlauth.saml_controller_acs',
    'samlauth.saml_controller_sls',
  ];

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * Path validator.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected $pathValidator;

  /**
   * Constructs a new redirect subscriber.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\Core\Path\PathValidatorInterface $path_validator
   *   Path validator.
   */
  public function __construct(AccountInterface $account, PathValidatorInterface $path_validator) {
    $this->account = $account;
    $this->pathValidator = $path_validator;
  }

  /**
   * Redirects users when access is denied.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent $event
   *   The event to process.
   */
  public function onException(GetResponseForExceptionEvent $event) {
    $exception = $event->getException();
    if ($exception instanceof TooManyRequestsHttpException) {
      $route_name = $this->getCurrentRouteName($event);
      if (in_array($route_name, self::FLOOD_CONTROL_ROUTES)) {
        // Don't spend time on a RedirectResponse (when the redirected page
        // will need to spend time rendering the page that includes an error
        // message). Just a simple text string should do.
        $event->setResponse(new Response($exception->getMessage(), $exception->getStatusCode()));
      }
    }
    if ($exception instanceof AccessDeniedHttpException && $this->account->isAuthenticated()) {
      $route_name = $this->getCurrentRouteName($event);
      if (in_array($route_name, self::INTERNALROUTES)) {
        // If a RelayState is provided the allow that redirection to happen,
        // otherwise redirect an authenticated user to the profile page.
        if ($relay_state = $event->getRequest()->request->get('RelayState')) {
          /* @var $relay_url Url */
          if ($relay_url = $this->pathValidator->getUrlIfValidWithoutAccessCheck($relay_state)) {
            $url = $relay_url->toUriString();
          }
        }
        else {
          $url = Url::fromRoute('entity.user.canonical', ['user' => $this->account->id()])
            ->toString(TRUE)
            ->getGeneratedUrl();
        }
        $event->setResponse(new LocalRedirectResponse($url));
      }
    }
  }

  /**
   * Gets the current route name.
   *
   * @param \Symfony\Component\HttpKernel\Event\KernelEvent $event
   *   The event we're subscribed to.
   *
   * @return string
   *   The current route name.
   */
  private function getCurrentRouteName(KernelEvent $event) {
    // This method is just a reminder: we can either get the current request
    // from the event, or we can inject the current_route_match service if ever
    // necessary. There seems to be no consensus on what is 'better'.
    return RouteMatch::createFromRequest($event->getRequest())->getRouteName();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Use a higher priority than
    // \Drupal\Core\EventSubscriber\ExceptionLoggingSubscriber, because there's
    // no need to log the exception if we can redirect.
    $events[KernelEvents::EXCEPTION][] = ['onException', 75];
    return $events;
  }

}
