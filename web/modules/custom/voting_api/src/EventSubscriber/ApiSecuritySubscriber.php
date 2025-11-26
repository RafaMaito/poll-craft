<?php

declare(strict_types=1);

namespace Drupal\voting_api\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds basic security controls for Voting API:
 * - IP-based rate limiting using Redis
 * - Content-Type and JSON payload validation for POST /vote
 */
final class ApiSecuritySubscriber implements EventSubscriberInterface {

  /**
   * ApiSecuritySubscriber constructor.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Prioridade alta para rodar antes do controller.
      KernelEvents::REQUEST => ['onRequest', 100],
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

    // 1) Rate limiting (Redis).
    if (!$this->checkRateLimit($request, $event)) {
      // checkRateLimit já setou a response 429.
      return;
    }

    // 2) Validação extra para POST /api/voting/vote.
    if ($routeName === 'voting_api.vote' && $request->getMethod() === 'POST') {
      $this->validateVoteRequest($request);
    }
  }

  /**
   * Rate limiting via Redis.
   *
   * @return bool
   *   TRUE se estiver dentro do limite, FALSE se já respondeu 429.
   */
  private function checkRateLimit(Request $request, RequestEvent $event): bool {
    $ip = $request->getClientIp() ?? 'unknown';

    // Permite configurar via voting_core.settings se quiser.
    $config = $this->configFactory->get('voting_core.settings');
    $limit = (int) ($config->get('api_rate_limit_per_ip') ?? 100);
    $window = (int) ($config->get('api_rate_limit_window') ?? 60);

    $key = sprintf('voting_api:rate_limit:%s', $ip);

    /** @var \Redis $redis */
    $redis = \Drupal::service('redis.factory')->get();

    $count = $redis->incr($key);

    if ($count === 1) {
      // Expirar contador após $window segundos.
      $redis->expire($key, $window);
    }

    if ($count > $limit) {
      $this->logger->warning('Voting API rate limit exceeded.', [
        'ip' => $ip,
        'count' => $count,
        'limit' => $limit,
        'window' => $window,
      ]);

      $response = new JsonResponse(
        ['error' => 'Rate limit exceeded'],
        429
      );

      // Cabeçalhos opcionais de rate limit.
      $response->headers->set('X-RateLimit-Limit', (string) $limit);
      $response->headers->set('X-RateLimit-Remaining', '0');
      $response->headers->set('X-RateLimit-Reset', (string) (time() + $window));

      $event->setResponse($response);
      return FALSE;
    }

    // Se quiser, pode expor cabeçalhos *depois* em outro subscriber de RESPONSE.
    return TRUE;
  }

  /**
   * Validates Content-Type and JSON payload for vote endpoint.
   */
  private function validateVoteRequest(Request $request): void {
    $contentType = (string) $request->headers->get('Content-Type', '');
    if (!str_starts_with($contentType, 'application/json')) {
      throw new BadRequestHttpException('Content-Type must be application/json.');
    }

    $content = $request->getContent();
    if (strlen($content) > 1024 * 1024) {
      throw new BadRequestHttpException('Payload too large. Max 1MB.');
    }

    $decoded = json_decode($content, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
      throw new BadRequestHttpException('Invalid JSON payload.');
    }

    if (!isset($decoded['question_identifier'], $decoded['option_identifier'])) {
      throw new BadRequestHttpException('Missing required fields: question_identifier, option_identifier.');
    }
  }
}
