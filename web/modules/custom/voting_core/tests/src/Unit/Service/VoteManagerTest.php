<?php

declare(strict_types=1);

namespace Drupal\Tests\voting_core\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\voting_core\Exception\VoteException;
use Drupal\voting_core\Service\VoteManager;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Unit tests for the VoteManager service.
 *
 * @coversDefaultClass \Drupal\voting_core\Service\VoteManager
 * @group voting_core
 */
final class VoteManagerTest extends UnitTestCase {

  /**
   * Creates a config mock backed by an associative array.
   *
   * @param array<string, mixed> $values
   *   The configuration values.
   */
  private function createConfig(array $values): ImmutableConfig {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn($key, $default = NULL) => array_key_exists($key, $values) ? $values[$key] : $default
    );
    return $config;
  }

  /**
   * Creates an entity mock with id() and get() stubs.
   */
  private function createEntity(int $id, array $fieldValues = []): ContentEntityInterface {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('id')->willReturn($id);
    $entity->method('get')->willReturnCallback(
      static fn($field) => (object) ['value' => $fieldValues[$field] ?? NULL]
    );
    return $entity;
  }

  /**
   * Builds a VoteManager with mocked dependencies.
   *
   * @param array<string, mixed> $configValues
   *   The voting_core.settings values.
   * @param int $uid
   *   The current user ID.
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $questions
   *   Questions returned by loadByProperties.
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $options
   *   Options returned by loadByProperties.
   * @param int[] $existingVoteIds
   *   Result of the duplicate-check query.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $createdVote
   *   The vote entity returned by create().
   *
   * @return array{0: \Drupal\voting_core\Service\VoteManager, 1: \Symfony\Contracts\EventDispatcher\EventDispatcherInterface}
   *   The service and the event dispatcher mock.
   */
  private function buildVoteManager(
    array $configValues = [],
    int $uid = 1,
    array $questions = [],
    array $options = [],
    array $existingVoteIds = [],
    ?ContentEntityInterface $createdVote = NULL,
  ): array {
    $configValues += [
      'voting_enabled' => TRUE,
      'allow_anonymous_voting' => FALSE,
      'max_votes_per_hour' => 0,
    ];

    $config = $this->createConfig($configValues);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('voting_core.settings')->willReturn($config);

    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('id')->willReturn($uid);

    $questionStorage = $this->createMock(EntityStorageInterface::class);
    $questionStorage->method('loadByProperties')->willReturn($questions);

    $optionStorage = $this->createMock(EntityStorageInterface::class);
    $optionStorage->method('loadByProperties')->willReturn($options);

    $voteStorage = $this->createMock(EntityStorageInterface::class);
    if ($createdVote !== NULL) {
      $voteStorage->method('create')->willReturn($createdVote);
    }

    $query = $this->createMock(\Drupal\Core\Entity\Query\QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($existingVoteIds);
    $voteStorage->method('getQuery')->willReturn($query);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnCallback(
      static function (string $type) use ($questionStorage, $optionStorage, $voteStorage) {
        return match ($type) {
          'question' => $questionStorage,
          'option' => $optionStorage,
          'vote' => $voteStorage,
        };
      }
    );

    $transaction = new class {

      /**
       * No-op rollback for the test double.
       */
      public function rollBack(): void {
      }

    };
    $database = $this->createMock(Connection::class);
    $database->method('startTransaction')->willReturn($transaction);

    $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1700000000);

    $logger = $this->createMock(LoggerInterface::class);

    $voteManager = new VoteManager(
      $entityTypeManager,
      $currentUser,
      $logger,
      $configFactory,
      $database,
      $eventDispatcher,
      $time,
    );

    return [$voteManager, $eventDispatcher];
  }

  /**
   * @covers ::castVote
   */
  public function testCastVoteThrowsExceptionWhenVotingDisabled(): void {
    [$voteManager] = $this->buildVoteManager(['voting_enabled' => FALSE]);

    $this->expectException(VoteException::class);
    $this->expectExceptionMessage('Voting is currently disabled.');

    $voteManager->castVote('favorite-color', 'red');
  }

  /**
   * @covers ::castVote
   */
  public function testCastVoteThrowsExceptionForAnonymousUser(): void {
    [$voteManager] = $this->buildVoteManager(
      ['allow_anonymous_voting' => FALSE],
      uid: 0,
    );

    $this->expectException(VoteException::class);
    $this->expectExceptionMessage('Anonymous users are not allowed to vote.');

    $voteManager->castVote('favorite-color', 'red');
  }

  /**
   * @covers ::castVote
   */
  public function testCastVoteThrowsExceptionForInactiveQuestion(): void {
    $question = $this->createEntity(1, ['status' => FALSE, 'voting_end_date' => NULL]);
    [$voteManager] = $this->buildVoteManager(questions: [$question]);

    $this->expectException(VoteException::class);
    $this->expectExceptionMessage('This question is not currently active.');

    $voteManager->castVote('favorite-color', 'red');
  }

  /**
   * @covers ::castVote
   */
  public function testCastVoteThrowsExceptionForInvalidOption(): void {
    $question = $this->createEntity(1, ['status' => TRUE, 'voting_end_date' => NULL]);
    [$voteManager] = $this->buildVoteManager(questions: [$question], options: []);

    $this->expectException(VoteException::class);
    $this->expectExceptionMessage('Invalid option for this question.');

    $voteManager->castVote('favorite-color', 'red');
  }

  /**
   * @covers ::castVote
   */
  public function testCastVoteThrowsExceptionForDuplicateVote(): void {
    $question = $this->createEntity(1, ['status' => TRUE, 'voting_end_date' => NULL]);
    $option = $this->createEntity(10, ['identifier' => 'red']);
    [$voteManager] = $this->buildVoteManager(
      questions: [$question],
      options: [$option],
      existingVoteIds: [42],
    );

    $this->expectException(VoteException::class);
    $this->expectExceptionMessage('You have already voted for this question.');

    $voteManager->castVote('favorite-color', 'red');
  }

  /**
   * The full success path (save + event dispatch) is covered by the Kernel
   * integration test, which uses real entities and the database.
   *
   * @see \Drupal\Tests\voting_core\Kernel\Service\VoteManagerIntegrationTest::testCastVoteIntegration()
   */
  public function testCastVoteHandlesStorageException(): void {
    $question = $this->createEntity(1, ['status' => TRUE, 'voting_end_date' => NULL]);
    $option = $this->createEntity(10, ['identifier' => 'red']);

    $vote = $this->createMock(ContentEntityInterface::class);
    $vote->method('save')->willThrowException(
      new \Drupal\Core\Entity\EntityStorageException('Could not save vote.')
    );

    [$voteManager] = $this->buildVoteManager(
      questions: [$question],
      options: [$option],
      createdVote: $vote,
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Could not register vote. Please try again.');

    $voteManager->castVote('favorite-color', 'red');
  }

}
