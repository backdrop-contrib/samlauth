<?php

namespace Drupal\samlauth\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\Core\Utility\Error;
use Drupal\Core\Utility\Token;
use Drupal\samlauth\SamlService;
use Drupal\samlauth\UserVisibleException;
use OneLogin\Saml2\Utils;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Returns responses for samlauth module routes.
 */
class SamlController extends ControllerBase {

  use ExecuteInRenderContextTrait;

  /**
   * Name of the configuration object containing the setting used by this class.
   */
  const CONFIG_OBJECT_NAME = 'samlauth.authentication';

  /**
   * The samlauth SAML service.
   *
   * @var \Drupal\samlauth\SamlService
   */
  protected $saml;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The PathValidator service.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected $pathValidator;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * SamlController constructor.
   *
   * @param \Drupal\samlauth\SamlService $saml
   *   The samlauth SAML service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Path\PathValidatorInterface $path_validator
   *   The PathValidator service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The Renderer service.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   */
  public function __construct(SamlService $saml, RequestStack $request_stack, ConfigFactoryInterface $config_factory, PathValidatorInterface $path_validator, RendererInterface $renderer, Token $token, MessengerInterface $messenger, LoggerInterface $logger) {
    $this->saml = $saml;
    $this->requestStack = $request_stack;
    $this->configFactory = $config_factory;
    $this->pathValidator = $path_validator;
    $this->renderer = $renderer;
    $this->token = $token;
    $this->messenger = $messenger;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('samlauth.saml'),
      $container->get('request_stack'),
      $container->get('config.factory'),
      $container->get('path.validator'),
      $container->get('renderer'),
      $container->get('token'),
      $container->get('messenger'),
      $container->get('logger.channel.samlauth')
    );
  }

  /**
   * Initiates a SAML2 authentication flow.
   *
   * This route does not log us in (yet); it should redirect to the Login
   * service on the IdP, which should be redirecting back to our ACS endpoint
   * after authenticating the user.
   *
   * @return \Drupal\Core\Routing\TrustedRedirectResponse
   */
  public function login() {
    // $function returns a string and supposedly never calls 'external' Drupal
    // code... so it wouldn't need to be executed inside a render context. The
    // standard exception handling does, though.
    $function = function () {
      return $this->saml->login($this->getUrlFromDestination());
    };
    // This response redirects to an external URL in all/common cases. We count
    // on the routing.yml to specify that it's not cacheable.
    return $this->getTrustedRedirectResponse($function, 'initiating SAML login', '<front>');
  }

  /**
   * Initiates a SAML2 logout flow.
   *
   * According to the SAML spec, this route does not log us out (yet); it
   * should redirect to the SLS service on the IdP, which should be redirecting
   * back to our SLS endpoint (possibly first logging out from other systems
   * first). We do usually log out before redirecting, though.
   *
   * @return \Drupal\Core\Routing\TrustedRedirectResponse
   */
  public function logout() {
    // $function returns a string and supposedly never calls 'external' Drupal
    // code... so it wouldn't need to be executed inside a render context. The
    // standard exception handling does, though.
    $function = function () {
      return $this->saml->logout($this->getUrlFromDestination());
    };
    // This response redirects to an external URL in all/common cases. We count
    // on the routing.yml to specify that it's not cacheable.
    return $this->getTrustedRedirectResponse($function, 'initiating SAML logout', '<front>');
  }

  /**
   * Displays service provider metadata XML for iDP autoconfiguration.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function metadata() {
    try {
      $metadata = $this->saml->getMetadata();
    }
    catch (\Exception $e) {
      // This (invoking the exception handling that executes inside a render
      // context) is an awfully convoluted way of handling the exception - but
      // it reuses code and generates the redirect response in a 'protected'
      // way. (Is it even useful to redirect to the front page with an error
      // message? It will not help non-humans requesting the XML document. But
      // humans checking this path will at least see a better hint of what's
      // going on, than if we just return Drupal's plain general exception
      // response. And rendering an error page without redirecting... seems too
      // much effort.)
      $function = function () use ($e) {
        throw $e;
      };
      return $this->getTrustedRedirectResponse($function, 'processing SAML SP metadata', '<front>');
    }

    // The metadata is a 'regular' response and should be cacheable.
    // @todo debugging option: make it not cacheable.
    return new CacheableResponse($metadata, 200, ['Content-Type' => 'text/xml']);
  }

  /**
   * Performs the Attribute Consumer Service.
   *
   * This is usually the second step in the authentication flow; the Login
   * service on the IdP should redirect (or: execute a POST request to) here.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function acs() {
    // We don't necessarily need to wrap our code in a render context: because
    // our redirect is always internal, we could work with a plain
    // non-cacheable RedirectResponse which will not cause a "leaked metadata"
    // exception even if some code leaks metadata. But we'll use the same
    // pattern as our other routes, for consistency/code reuse, and to log more
    // possible 'leaky' code. We count on the routing.yml to specify the
    // response is not cacheable.
    $function = function () {
      $this->saml->acs();
      return $this->getRedirectUrlAfterProcessing(TRUE);
    };
    return $this->getTrustedRedirectResponse($function, 'processing SAML authentication response', '<front>');
  }

  /**
   * Performs the Single Logout Service.
   *
   * This is usually the second step in the logout flow; the SLS service on the
   * IdP should redirect here.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function sls() {
    $function = function () {
      return $this->saml->sls() ?: $this->getRedirectUrlAfterProcessing();
    };
    // This response redirects to an external URL in most cases. (Except for
    // SP-initiated logout that was initially started from this SP, i.e.
    // through the logout() route). We count on the routing.yml to specify that
    // it's not cacheable.
    return $this->getTrustedRedirectResponse($function, 'processing SAML single-logout response', '<front>');
  }

  /**
   * Redirects to the 'Change Password' service.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  public function changepw() {
    $function = function () {
      $url = $this->config(self::CONFIG_OBJECT_NAME)->get('idp_change_password_service');
      if (!$url) {
        throw new UserVisibleException("Change password service is not available.");
      }
      return $url;
    };
    // This response is cached. (We should probably clear it from the cache
    // when the configuration is changed. On a half related note: we should
    // probably also have at least one 'user story' or other note about this
    // endpoint. The current reason for this only being available for logged-in
    // users is "v1 did it this way and there has been no reason/request to
    // change it" but we don't know if this is generally applicable for IdPs.)
    return $this->getTrustedRedirectResponse($function, '', '<front>');
  }

  /**
   * Constructs a full URL from the 'destination' parameter.
   *
   * Also unsets the destination parameter. This is only considered suitable
   * for feeding a URL string into php-saml's login() / logout() methods.
   *
   * @return string|null
   *   The full absolute URL (i.e. our hostname plus the path in the destination
   *   parameter), or NULL if no destination parameter was given. This value is
   *   tuned to what login() / logout() expect for an input argument.
   *
   * @throws \Drupal\samlauth\UserVisibleException
   *   If the destination is disallowed.
   */
  protected function getUrlFromDestination() {
    $destination_url = NULL;
    $request_query_parameters = $this->requestStack->getCurrentRequest()->query;
    $destination = $request_query_parameters->get('destination');
    if ($destination) {
      if (UrlHelper::isExternal($destination)) {
        // Disallow redirecting to an external URL after we log in.
        throw new UserVisibleException("Destination URL query parameter must not be external: $destination");
      }
      $destination_url = $GLOBALS['base_url'] . '/' . $destination;

      // After we return from this controller, Drupal immediately redirects to
      // the path set in the 'destination' parameter (for the current URL being
      // handled). We want to always redirect to the IdP instead (and only use
      // $destination_url after the user gets redirected back here), so remove
      // the parameter.
      $request_query_parameters->remove('destination');
    }

    return $destination_url;
  }

  /**
   * Returns a URL to redirect to.
   *
   * This should be called only after successfully processing an ACS/logout
   * response.
   *
   * @param bool $logged_in
   *   (optional) TRUE if an ACS request was just processed.
   *
   * @return \Drupal\Core\Url
   *   The URL to redirect to.
   */
  protected function getRedirectUrlAfterProcessing($logged_in = FALSE) {
    $relay_state = $this->requestStack->getCurrentRequest()->get('RelayState');
    if ($relay_state) {
      // We should be able to trust the RelayState parameter at this point
      // because the response from the IdP was verified. Only validate general
      // syntax.
      if (!UrlHelper::isValid($relay_state, TRUE)) {
        $this->logger->error('Invalid RelayState parameter found in request: @relaystate', ['@relaystate' => $relay_state]);
      }
      // The SAML toolkit set a default RelayState to itself (saml/log(in|out))
      // when starting the process; ignore this value.
      elseif (strpos($relay_state, Utils::getSelfURLhost() . '/saml/') !== 0) {
        $url = $relay_state;
      }
    }

    if (empty($url)) {
      // If no url was specified, we check if it was configured.
      $url = $this->config(self::CONFIG_OBJECT_NAME)->get($logged_in ? 'login_redirect_url' : 'logout_redirect_url');
    }

    if ($url) {
      $url = $this->token->replace($url);
      // We don't check access here. If a URL was explicitly specified, we
      // prefer returning a 403 over silently redirecting somewhere else.
      $url_object = $this->pathValidator->getUrlIfValidWithoutAccessCheck($url);
      if (empty($url_object)) {
        $type = $logged_in ? 'Login' : 'Logout';
        $this->logger->warning("The $type Redirect URL is not a valid path; falling back to default.");
      }
    }

    if (empty($url_object)) {
      // If no url was configured, fall back to a hardcoded route.
      $url_object = Url::fromRoute($logged_in ? 'user.page' : '<front>');
    }

    return $url_object;
  }

  /**
   * Displays and/or logs exception message if a wrapped callable fails.
   *
   * Only called by getTrustedRedirectResponse() so far. Can be overridden to
   * implement other ways of logging.
   *
   * @param \Exception $exception
   *   The exception thrown.
   * @param string $while
   *   (Optional) description of when the error was encountered.
   */
  protected function handleExceptionInRenderContext(\Exception $exception, $while = '') {
    if ($exception instanceof UserVisibleException || $this->config(self::CONFIG_OBJECT_NAME)->get('debug_display_error_details')) {
      // Show the full error on screen; also log, but with lowered severity.
      // Assume we don't need the "while" part for a user visible error because
      // it's likely not fully correct.
      $this->messenger->addError($exception->getMessage());
      $this->logger->warning($exception->getMessage());
    }
    else {
      // Use the same format for logging as Drupal's ExceptionLoggingSubscriber
      // except also specify where the error was encountered. (The options for
      // the "while" part are limited, so we make this part of the message
      // rather than a context parameter.)
      if ($while) {
        $while = " while $while";
      }
      $error = Error::decodeException($exception);
      unset($error['severity_level']);
      $this->logger->critical("%type encountered$while: @message in %function (line %line of %file).", $error);
      // Don't expose the error to prevent information leakage; the user probably
      // can't do much with it anyway. But hint that more details are available.
      $this->messenger->addError("Error encountered{$while}; details have been logged.");
    }
  }

}
