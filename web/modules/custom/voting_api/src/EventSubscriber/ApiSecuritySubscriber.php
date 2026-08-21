<?php

declare(strict_types=1);

namespace Drupal\voting_api\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds basic security controls for the Voting API.
 *
 * - IP-based rate limiting using the Flood API (database-backed).
 * - Content-Type and JSON payload validation for POST /vote.
 */
final class ApiSecuritySubscriber implements EventSubscriberInterface {

  /**
   * ApiSecuritySubscriber constructor.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly FloodInterface $flood,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('logger.channel.voting_api'),
      $container->get('flood'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Runs after routing (RouterListener has priority 32) so that the route
      // name is available, but before the controller executes.
      KernelEvents::REQUEST => ['onRequest', 28],
    ];
  }

  /**
   * Handles security for all voting_api.* routes.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    $routeName = (string) $request->attributes->get('_route');

    // Apenas rotas do voting_api.
    if (!str_starts_with($routeName, 'voting_api.')) {
      return;
    }

    // 1) Rate limiting (Flood API).
    if (!$this->checkRateLimit($request, $event)) {
      // checkRateLimit já setou a response 429.
      return;
    }

    // 2) Validação extra para POST /api/voting/vote.
    if ($routeName === 'voting_api.vote' && $request->getMethod() === 'POST') {
      if (!$this->validateVoteRequest($request, $event)) {
        return;
      }
    }
  }

  /**
   * Rate limiting via Flood API (identificador baseado no IP).
   *
   * @return bool
   *   TRUE se estiver dentro do limite, FALSE se já respondeu 429.
   */
  private function checkRateLimit(Request $request, RequestEvent $event): bool {
    $config = $this->configFactory->get('voting_core.settings');
    $limit = (int) ($config->get('api_rate_limit_per_ip') ?? 100);
    $window = (int) ($config->get('api_rate_limit_window') ?? 60);

    // Identificador baseado no IP (não registra o IP nos logs — evita PII).
    $identifier = sprintf('voting_api:%s', $request->getClientIp() ?? 'unknown');

    if (!$this->flood->isAllowed('voting_api', $limit, $window, $identifier)) {
      $this->logger->warning('Voting API rate limit exceeded.', [
        'limit' => $limit,
        'window' => $window,
      ]);

      $response = new JsonResponse(
        ['error' => 'Rate limit exceeded'],
        429
      );

      $response->headers->set('X-RateLimit-Limit', (string) $limit);
      $response->headers->set('X-RateLimit-Remaining', '0');
      $response->headers->set('X-RateLimit-Reset', (string) (time() + $window));

      $event->setResponse($response);
      return FALSE;
    }

    // Registra a tentativa para contabilizar no Flood API.
    $this->flood->register('voting_api', $window, $identifier);

    return TRUE;
  }

  /**
   * Validates Content-Type and JSON payload for vote endpoint.
   */
  private function validateVoteRequest(Request $request, RequestEvent $event): bool {
    $contentType = (string) $request->headers->get('Content-Type', '');
    if (!str_starts_with($contentType, 'application/json')) {
      $this->setErrorResponse($event, 'Content-Type must be application/json.', 400);
      return FALSE;
    }

    $content = $request->getContent();
    if (strlen($content) > 1024 * 1024) {
      $this->setErrorResponse($event, 'Payload too large. Max 1MB.', 400);
      return FALSE;
    }

    $decoded = json_decode($content, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
      $this->setErrorResponse($event, 'Invalid JSON payload.', 400);
      return FALSE;
    }

    if (!isset($decoded['question_identifier'], $decoded['option_identifier'])) {
      $this->setErrorResponse($event, 'Missing required fields: question_identifier, option_identifier.', 400);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Sets a JSON error response and stops the request.
   */
  private function setErrorResponse(RequestEvent $event, string $message, int $status): void {
    $event->setResponse(new JsonResponse(['error' => $message], $status));
  }
}
