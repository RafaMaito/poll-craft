<?php

declare(strict_types=1);

namespace Drupal\voting_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Drupal\voting_core\Event\VoteEvent;
use Drupal\voting_core\Exception\VoteException;

/**
 * Manages vote operations with ACID transactions and security.
 *
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
    private readonly TimeInterface $time,
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
   * @throws \Drupal\voting_core\Exception\VoteException
   *   When business rules are violated.
   * @throws \RuntimeException
   *   When an unexpected storage/system error occurs.
   */
  public function castVote(string $questionIdentifier, string $optionIdentifier): void {

    // Global voting status check.
    $config = $this->configFactory->get('voting_core.settings');
    if ($config->get('voting_enabled') === FALSE) {
      throw new VoteException('Voting is currently disabled.', VoteException::DISABLED);
    }

    // User authentication.
    $uid = (int) $this->currentUser->id();
    if ($uid === 0) {
      $allowAnonymous = (bool) $config->get('allow_anonymous_voting');
      if (!$allowAnonymous) {
        throw new VoteException('Anonymous users are not allowed to vote.', VoteException::ANONYMOUS_NOT_ALLOWED);
      }
      throw new VoteException('Anonymous voting is not available.', VoteException::ANONYMOUS_NOT_ALLOWED);
    }

    // Rate limiting.
    if (!$this->checkRateLimit($uid)) {
      throw new VoteException('Too many vote attempts. Please try again later.', VoteException::RATE_LIMIT);
    }

    // Load and validate question.
    $questionStorage = $this->entityTypeManager->getStorage('question');
    $questions = $questionStorage->loadByProperties([
      'identifier' => $questionIdentifier,
    ]);
    $question = reset($questions) ?: NULL;
    /** @var \Drupal\voting_core\Entity\Question|null $question */

    if ($question === NULL) {
      $this->logger->warning('Vote attempt for non-existent question', [
        'identifier' => $questionIdentifier,
        'user_id' => $uid,
      ]);
      throw new VoteException('Question not found.', VoteException::QUESTION_NOT_FOUND);
    }

    // Validate question is active.
    if ((bool) $question->get('status')->value === FALSE) {
      throw new VoteException('This question is not currently active.', VoteException::QUESTION_INACTIVE);
    }

    // Validate voting end date (if configured).
    $endDate = $question->get('voting_end_date')->value;
    if ($endDate) {
      try {
        $endTimestamp = (new \DateTimeImmutable($endDate, new \DateTimeZone('UTC')))
          ->getTimestamp();
      } catch (\Exception $e) {
        $endTimestamp = NULL;
      }

      if ($endTimestamp !== NULL && $endTimestamp < $this->time->getRequestTime()) {
        throw new VoteException('This question is no longer accepting votes.', VoteException::VOTING_CLOSED);
      }
    }

    // Load and validate option.
    $optionStorage = $this->entityTypeManager->getStorage('option');
    $options = $optionStorage->loadByProperties([
      'identifier' => $optionIdentifier,
      'question' => $question->id(),
    ]);
    $option = reset($options) ?: NULL;
    /** @var \Drupal\voting_core\Entity\Option|null $option */

    if ($option === NULL) {
      // SECURITY: This might be an attempt to manipulate.
      $this->logger->warning('Vote attempt with invalid option', [
        'question_identifier' => $questionIdentifier,
        'option_identifier' => $optionIdentifier,
        'user_id' => $uid,
      ]);
      throw new VoteException('Invalid option for this question.', VoteException::INVALID_OPTION);
    }

    // Transaction for duplicate check + insert.
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
        throw new VoteException('You have already voted for this question.', VoteException::DUPLICATE);
      }

      // Create vote entity.
      $vote = $voteStorage->create([
        'question' => $question->id(),
        'option' => $option->id(),
        'user_id' => $uid,
      ]);
      /** @var \Drupal\voting_core\Entity\Vote $vote */

      $vote->save();

      // Commit transaction.
      unset($transaction);

      // Dispatch event for external sync.
      $event = new VoteEvent($vote, $question, $option);
      $this->eventDispatcher->dispatch($event, VoteEvent::NAME);

      $this->logger->info('Vote cast successfully', [
        'user_id' => $uid,
        'question_id' => $question->id(),
        'option_id' => $option->id(),
      ]);
    } catch (EntityStorageException $e) {
      $transaction->rollBack();

      // A constraint única (question, user_id) protege contra voto duplicado
      // em alta concorrência, quando a checagem prévia não é suficiente.
      if ($this->isDuplicateKeyViolation($e)) {
        throw new VoteException('You have already voted for this question.', VoteException::DUPLICATE);
      }

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

    // If no limit set, always allow.
    if ($maxVotesPerHour <= 0) {
      return TRUE;
    }

    // Count votes in last hour.
    $voteStorage = $this->entityTypeManager->getStorage('vote');
    $query = $voteStorage->getQuery()
      ->condition('user_id', $userId)
      ->condition('created', $this->time->getRequestTime() - 3600, '>')
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
   * Detects a database duplicate-key violation from a storage exception.
   *
   * @param \Drupal\Core\Entity\EntityStorageException $e
   *   The caught exception.
   *
   * @return bool
   *   TRUE when the exception chain contains a duplicate-key error.
   */
  private function isDuplicateKeyViolation(EntityStorageException $e): bool {
    $previous = $e->getPrevious();
    while ($previous instanceof \Throwable) {
      if ($previous instanceof \PDOException) {
        $driverCode = $previous->errorInfo[1] ?? NULL;
        if ($previous->getCode() === '23000' || $driverCode === 1062) {
          return TRUE;
        }
      }
      $previous = $previous->getPrevious();
    }

    return FALSE;
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
    /** @var \Drupal\voting_core\Entity\Vote $vote */

    /** @var \Drupal\voting_core\Entity\Option|null $option */
    $option = $vote->get('option')->entity;
    return $option ? $option->get('identifier')->value : NULL;
  }
}
