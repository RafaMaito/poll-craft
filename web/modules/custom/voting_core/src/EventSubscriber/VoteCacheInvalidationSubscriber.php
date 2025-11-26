<?php

declare(strict_types=1);

namespace Drupal\voting_core\EventSubscriber;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\voting_core\Event\VoteEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Invalidates cached voting results when a vote is cast.
 * GOAL:
 * - Ensure users see up-to-date results after voting.
 * - Maintain cache consistency with vote data.
 * 
 */
final class VoteCacheInvalidationSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly CacheBackendInterface $cacheBackend,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Gets the subscribed events.
   * 
   */
  public static function getSubscribedEvents(): array {
    return [
      VoteEvent::NAME => 'onVoteCast',
    ];
  }

  /**
   * Clears cached results for the question.
   */
  public function onVoteCast(VoteEvent $event): void {
    $question = $event->getQuestion();
    $identifier = $question->get('identifier')->value;

    // Cache key format must match the one in getResultsForApi().
    $cacheKey = "voting_core:results:$identifier";

    // Delete single-key cache.
    $this->cacheBackend->delete($cacheKey);

    // Also invalidate cache tags if used.
    \Drupal::service('cache_tags.invalidator')->invalidateTags([
      "question:{$question->id()}",
      'question_results',
    ]);

    $this->logger->debug('Voting results cache invalidated after vote', [
      'question_identifier' => $identifier,
      'question_id' => $question->id(),
      'user_id' => $event->getUserId(),
    ]);
  }

}
