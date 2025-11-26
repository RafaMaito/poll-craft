<?php

declare(strict_types=1);

namespace Drupal\voting_core\Service;

use Drupal\Core\Access\AccessException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Drupal\voting_core\Event\VoteEvent;

/**
 * Manages vote operations with ACID transactions and security.
 * GOAL:
 * - Ensure data integrity with transactions.
 * - Enforce business rules: one vote per user per question.
 * - Dispatch events for external integrations post-vote.
 */
final class VoteManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Connection $database,
    private readonly EventDispatcherInterface $eventDispatcher,
  ) {
  }

  /**
   * Casts a vote with comprehensive validation and security checks.
   *
   * @param string $questionIdentifier
   *   The question identifier.
   * @param string $optionIdentifier
   *   The option identifier.
   *
   * @throws \RuntimeException
   *   When business rules are violated.
   * @throws \Drupal\Core\Access\AccessException
   *   When security checks fail.
   */
  public function castVote(string $questionIdentifier, string $optionIdentifier): void {

    // Global voting status check
    $config = $this->configFactory->get('voting_core.settings');
    if ($config->get('voting_enabled') === FALSE) {
      throw new \RuntimeException('Voting is currently disabled.');
    }

    // User authentication
    $uid = (int) $this->currentUser->id();
    if ($uid === 0) {
      $allowAnonymous = (bool) $config->get('allow_anonymous_voting');
      if (!$allowAnonymous) {
        throw new \RuntimeException('Anonymous users are not allowed to vote.');
      }
      throw new \RuntimeException('Anonymous voting not yet implemented.');
    }

    // Rate limiting
    if (!$this->checkRateLimit($uid)) {
      throw new \RuntimeException('Too many vote attempts. Please try again later.');
    }

    // Load and validate question
    $questionStorage = $this->entityTypeManager->getStorage('question');
    $questions = $questionStorage->loadByProperties([
      'identifier' => $questionIdentifier,
    ]);
    $question = reset($questions) ?: NULL;

    if ($question === NULL) {
      $this->logger->warning('Vote attempt for non-existent question', [
        'identifier' => $questionIdentifier,
        'user_id' => $uid,
      ]);
      throw new \RuntimeException('Question not found.');
    }

    // Validate question is active
    if ((bool) $question->get('status')->value === FALSE) {
      throw new \RuntimeException('This question is not currently active.');
    }

    // Load and validate option
    $optionStorage = $this->entityTypeManager->getStorage('option');
    $options = $optionStorage->loadByProperties([
      'identifier' => $optionIdentifier,
      'question' => $question->id(),
    ]);
    $option = reset($options) ?: NULL;

    if ($option === NULL) {
      // SECURITY: This might be an attempt to manipulate
      $this->logger->warning('Vote attempt with invalid option', [
        'question_identifier' => $questionIdentifier,
        'option_identifier' => $optionIdentifier,
        'user_id' => $uid,
      ]);
      throw new \RuntimeException('Invalid option for this question.');
    }

    // Transaction for duplicate check + insert
    $transaction = $this->database->startTransaction();

    try {
      // Check for duplicate using entity query.
      $voteStorage = $this->entityTypeManager->getStorage('vote');
      $query = $voteStorage->getQuery()
        ->condition('question', $question->id())
        ->condition('user_id', $uid)
        ->accessCheck(FALSE)
        ->range(0, 1);

      $existingVoteIds = $query->execute();

      if (!empty($existingVoteIds)) {
        throw new \RuntimeException('You have already voted for this question.');
      }

      // Create vote entity
      $vote = $voteStorage->create([
        'question' => $question->id(),
        'option' => $option->id(),
        'user_id' => $uid,
      ]);

      $vote->save();

      // Commit transaction
      unset($transaction);

      // Dispatch event for external sync
      $event = new VoteEvent($vote, $question, $option);
      $this->eventDispatcher->dispatch($event, VoteEvent::NAME);

      $this->logger->info('Vote cast successfully', [
        'user_id' => $uid,
        'question_id' => $question->id(),
        'option_id' => $option->id(),
      ]);
    } catch (EntityStorageException $e) {
      $transaction->rollBack();

      $this->logger->error('Vote save failed: {message}', [
        'message' => $e->getMessage(),
        'user_id' => $uid,
        'exception' => $e,
      ]);

      throw new \RuntimeException('Could not register vote. Please try again.');
    } catch (\RuntimeException $e) {
      $transaction->rollBack();
      throw $e;
    } catch (\Exception $e) {
      $transaction->rollBack();

      $this->logger->critical('Unexpected error during vote casting', [
        'message' => $e->getMessage(),
        'exception' => $e,
      ]);

      throw new \RuntimeException('An unexpected error occurred.');
    }
  }

  /**
   * Check rate limiting for vote attempts.
   *
   * @param int $userId
   *   The user ID.
   *
   * @return bool
   *   TRUE if within rate limit, FALSE otherwise.
   */
  private function checkRateLimit(int $userId): bool {
    $config = $this->configFactory->get('voting_core.settings');
    $maxVotesPerHour = (int) ($config->get('max_votes_per_hour') ?? 0);

    // If no limit set, always allow
    if ($maxVotesPerHour <= 0) {
      return TRUE;
    }

    // Count votes in last hour
    $voteStorage = $this->entityTypeManager->getStorage('vote');
    $query = $voteStorage->getQuery()
      ->condition('user_id', $userId)
      ->condition('created', \Drupal::time()->getRequestTime() - 3600, '>')
      ->accessCheck(FALSE)
      ->count();

    $voteCount = (int) $query->execute();

    if ($voteCount >= $maxVotesPerHour) {
      $this->logger->warning('Rate limit exceeded', [
        'user_id' => $userId,
        'votes_in_hour' => $voteCount,
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Checks if user has voted for a question.
   *
   * @param string $questionIdentifier
   *   The question identifier.
   * @param int|null $userId
   *   The user ID (defaults to current user).
   *
   * @return bool
   *   TRUE if user has voted.
   */
  public function hasUserVoted(string $questionIdentifier, ?int $userId = NULL): bool {
    $uid = $userId ?? (int) $this->currentUser->id();

    if ($uid === 0) {
      return FALSE;
    }

    $questionStorage = $this->entityTypeManager->getStorage('question');
    $questions = $questionStorage->loadByProperties([
      'identifier' => $questionIdentifier,
    ]);
    $question = reset($questions) ?: NULL;

    if ($question === NULL) {
      return FALSE;
    }

    $voteStorage = $this->entityTypeManager->getStorage('vote');
    $query = $voteStorage->getQuery()
      ->condition('question', $question->id())
      ->condition('user_id', $uid)
      ->accessCheck(FALSE)
      ->range(0, 1);

    $count = $query->count()->execute();
    return $count > 0;
  }

  /**
   * Gets the option a user voted for.
   *
   * @param string $questionIdentifier
   *   The question identifier.
   * @param int|null $userId
   *   The user ID.
   *
   * @return string|null
   *   The option identifier or NULL.
   */
  public function getUserVote(string $questionIdentifier, ?int $userId = NULL): ?string {
    $uid = $userId ?? (int) $this->currentUser->id();

    if ($uid === 0) {
      return NULL;
    }

    $questionStorage = $this->entityTypeManager->getStorage('question');
    $questions = $questionStorage->loadByProperties([
      'identifier' => $questionIdentifier,
    ]);
    $question = reset($questions) ?: NULL;

    if ($question === NULL) {
      return NULL;
    }

    $voteStorage = $this->entityTypeManager->getStorage('vote');
    $votes = $voteStorage->loadByProperties([
      'question' => $question->id(),
      'user_id' => $uid,
    ]);

    $vote = reset($votes);
    if (!$vote) {
      return NULL;
    }

    $option = $vote->get('option')->entity;
    return $option ? $option->get('identifier')->value : NULL;
  }
}
