<?php

declare(strict_types=1);

namespace Drupal\voting_core\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\voting_core\Event\VoteEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to VoteEvent and enqueues external sync jobs.
 *
 * This keeps HTTP calls out of the request/response cycle.
 * GOAL:
 * - Decouple external system synchronization from core voting logic.
 * - Use Drupal's Queue API for reliable background processing.
 * 
 */
final class ExternalSyncSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly QueueFactory $queueFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Gets the subscribed events.
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      VoteEvent::NAME => 'onVoteCast',
    ];
  }

  /**
   * Reacts to a vote being cast and enqueues an external sync job.
   * @param \Drupal\voting_core\Event\VoteEvent $event
   *  The vote event.
   */
  public function onVoteCast(VoteEvent $event): void {
    $config = $this->configFactory->get('voting_core.settings');

    // Fast exit if external sync is disabled.
    if (!$config->get('external_sync_enabled')) {
      return;
    }

    // Get the queue for external sync.
    $queue = $this->queueFactory->get('voting_core_external_sync');

    // Prepare payload for the queue worker.
    $vote = $event->getVote();
    $question = $event->getQuestion();
    $option = $event->getOption();

    // Build the payload with necessary data.
    $payload = [
      'vote_id' => (int) $vote->id(),
      'question_id' => (int) $question->id(),
      'option_id' => (int) $option->id(),
      'user_id' => $event->getUserId(),
      'timestamp' => $event->getTimestamp(),
      'question_identifier' => $question->get('identifier')->value ?? NULL,
      'option_identifier' => $option->get('identifier')->value ?? NULL,
    ];

    // Enqueue the job.
    $queue->createItem($payload);

    $this->logger->info('Enqueued vote @id for external sync.', [
      '@id' => $vote->id(),
    ]);
  }

}
