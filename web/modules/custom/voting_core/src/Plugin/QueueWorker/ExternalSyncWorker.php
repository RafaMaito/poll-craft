<?php

declare(strict_types=1);

namespace Drupal\voting_core\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued vote sync items to an external API.
 *
 * @QueueWorker(
 *   id = "voting_core_external_sync",
 *   title = @Translation("Voting Core external sync worker"),
 *   cron = {"time" = 60}
 * )
 */
final class ExternalSyncWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * Each $data is a payload like:
   * [
   *   'vote_id' => 12,
   *   'question_id' => 3,
   *   'option_id' => 8,
   *   'user_id' => 1,
   *   'timestamp' => 1732624400,
   *   'question_identifier' => "favorite-color",
   *   'option_identifier' => "red"
   * ]
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('config.factory'),
      $container->get('logger.channel.voting_core'),
    );
  }

  /**
   * Processes a single item from the external sync queue.
   * {@inheritdoc}
   */
  public function processItem($data): void {
    // Validate payload format.
    if (!is_array($data)) {
      $this->logger->error('External sync aborted: invalid queue item type.', [
        'type' => gettype($data),
      ]);
      return;
    }

    // Check feature flag.
    $config = $this->configFactory->get('voting_core.settings');
    if (!$config->get('external_sync_enabled')) {
      $this->logger->notice('External sync skipped because feature flag is disabled.');
      return;
    }

    // Validate configuration.
    $url = trim((string) $config->get('external_api_url'));
    $apiKey = trim((string) $config->get('external_api_key'));

    if ($url === '' || $apiKey === '') {
      $this->logger->warning('External sync skipped: API URL or API key missing.');
      return;
    }

    // Make the HTTP POST request.
    try {
      $response = $this->httpClient->request('POST', $url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $apiKey,
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ],
        'json' => $data,
        'timeout' => 5,
      ]);

      $this->logger->info('External sync successful.', [
        'vote_id' => $data['vote_id'] ?? null,
        'status_code' => $response->getStatusCode(),
      ]);
    }
    catch (\Exception $e) {
      // Throwing here makes the item go back to queue for retry.
      $this->logger->error('External sync failed: @msg', [
        '@msg' => $e->getMessage(),
        'vote_id' => $data['vote_id'] ?? null,
        'question_id' => $data['question_id'] ?? null,
        'option_id' => $data['option_id'] ?? null,
      ]);

      throw $e;
    }
  }

}
