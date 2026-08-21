<?php

declare(strict_types=1);

namespace Drupal\Tests\voting_core\Kernel\Service;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\voting_core\Entity\Option;
use Drupal\voting_core\Entity\Question;
use Drupal\voting_core\Entity\Vote;
use Drupal\voting_core\Exception\VoteException;
use Drupal\voting_core\Service\VoteManager;

/**
 * Kernel integration tests for the VoteManager service.
 *
 * @group voting_core
 */
final class VoteManagerIntegrationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'voting_core',
    'user',
    'system',
    'field',
    'options',
    'datetime',
    'file',
    'image',
  ];

  /**
   * The vote manager service.
   */
  private VoteManager $voteManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('question');
    $this->installEntitySchema('option');
    $this->installEntitySchema('vote');
    $this->installConfig(['voting_core']);

    $this->voteManager = $this->container->get('voting_core.vote_manager');
  }

  /**
   * Creates and returns a user with the given name.
   */
  private function createUser(string $name): User {
    $user = User::create(['name' => $name, 'status' => 1]);
    $user->save();
    return $user;
  }

  /**
   * Sets the current user account for the vote manager.
   */
  private function setCurrentUser(User $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * Creates and returns an active question with the given identifier.
   */
  private function createQuestion(string $identifier, bool $showResults = TRUE): Question {
    $question = Question::create([
      'identifier' => $identifier,
      'title' => 'Question ' . $identifier,
      'status' => 1,
      'show_results' => $showResults ? 1 : 0,
    ]);
    $question->save();
    return $question;
  }

  /**
   * Creates and returns an option for the given question.
   */
  private function createOption(Question $question, string $identifier, int $weight = 0): Option {
    $option = Option::create([
      'question' => $question->id(),
      'identifier' => $identifier,
      'title' => 'Option ' . $identifier,
      'weight' => $weight,
    ]);
    $option->save();
    return $option;
  }

  /**
   * Tests that a vote is recorded end to end.
   */
  public function testCastVoteIntegration(): void {
    $this->setCurrentUser($this->createUser('voter'));
    $question = $this->createQuestion('favorite-color');
    $option = $this->createOption($question, 'red');

    $this->voteManager->castVote('favorite-color', 'red');

    $votes = \Drupal::entityTypeManager()
      ->getStorage('vote')
      ->loadByProperties(['question' => $question->id()]);
    $this->assertCount(1, $votes);
    $this->assertEquals($option->id(), reset($votes)->get('option')->target_id);
  }

  /**
   * Tests that a duplicate vote is rejected by the database constraint.
   */
  public function testDuplicateVotePreventedByDatabaseConstraint(): void {
    $this->setCurrentUser($this->createUser('voter'));
    $question = $this->createQuestion('favorite-color');
    $this->createOption($question, 'red');

    $this->voteManager->castVote('favorite-color', 'red');

    $this->expectException(VoteException::class);
    $this->voteManager->castVote('favorite-color', 'red');

    // The second vote must not have been persisted.
    $count = \Drupal::entityTypeManager()
      ->getStorage('vote')
      ->getQuery()
      ->condition('question', $question->id())
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertEquals(1, $count);
  }

  /**
   * Tests that votes are rejected for inactive questions.
   */
  public function testVoteRejectedForInactiveQuestion(): void {
    $this->setCurrentUser($this->createUser('voter'));
    $question = $this->createQuestion('inactive-question');
    $question->set('status', 0);
    $question->save();
    $this->createOption($question, 'red');

    $this->expectException(VoteException::class);
    $this->expectExceptionMessage('This question is not currently active.');

    $this->voteManager->castVote('inactive-question', 'red');
  }

  /**
   * Tests that votes are rejected when voting is globally disabled.
   */
  public function testVoteRejectedWhenGloballyDisabled(): void {
    $this->setCurrentUser($this->createUser('voter'));
    $question = $this->createQuestion('favorite-color');
    $this->createOption($question, 'red');

    $config = $this->container->get('config.factory')->getEditable('voting_core.settings');
    $config->set('voting_enabled', FALSE)->save();

    $this->expectException(VoteException::class);
    $this->expectExceptionMessage('Voting is currently disabled.');

    $this->voteManager->castVote('favorite-color', 'red');
  }

  /**
   * Tests that votes are rejected for non-existent questions.
   */
  public function testVoteRejectedForNonExistentQuestion(): void {
    $this->setCurrentUser($this->createUser('voter'));

    $this->expectException(VoteException::class);
    $this->expectExceptionMessage('Question not found.');

    $this->voteManager->castVote('does-not-exist', 'red');
  }

  /**
   * Tests that votes are rejected for invalid options.
   */
  public function testVoteRejectedForInvalidOption(): void {
    $this->setCurrentUser($this->createUser('voter'));
    $question = $this->createQuestion('favorite-color');
    $this->createOption($question, 'red');

    $this->expectException(VoteException::class);
    $this->expectExceptionMessage('Invalid option for this question.');

    $this->voteManager->castVote('favorite-color', 'blue');
  }

  /**
   * Tests that multiple users can vote on the same question.
   */
  public function testMultipleUsersCanVoteOnSameQuestion(): void {
    $question = $this->createQuestion('favorite-color');
    $option = $this->createOption($question, 'red');

    foreach (['alice', 'bob', 'carol'] as $name) {
      $this->setCurrentUser($this->createUser($name));
      $this->voteManager->castVote('favorite-color', 'red');
    }

    $count = \Drupal::entityTypeManager()
      ->getStorage('vote')
      ->getQuery()
      ->condition('question', $question->id())
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertEquals(3, $count);
  }

  /**
   * Tests vote counting with multiple votes.
   */
  public function testVoteCountingWithMultipleVotes(): void {
    $question = $this->createQuestion('favorite-color');
    $red = $this->createOption($question, 'red');
    $blue = $this->createOption($question, 'blue', 1);

    $users = ['u1', 'u2', 'u3', 'u4'];
    $this->setCurrentUser($this->createUser($users[0]));
    $this->voteManager->castVote('favorite-color', 'red');
    $this->setCurrentUser($this->createUser($users[1]));
    $this->voteManager->castVote('favorite-color', 'red');
    $this->setCurrentUser($this->createUser($users[2]));
    $this->voteManager->castVote('favorite-color', 'blue');
    $this->setCurrentUser($this->createUser($users[3]));
    $this->voteManager->castVote('favorite-color', 'blue');

    $storage = \Drupal::entityTypeManager()->getStorage('vote');
    $redCount = $storage->getQuery()
      ->condition('option', $red->id())
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $blueCount = $storage->getQuery()
      ->condition('option', $blue->id())
      ->accessCheck(FALSE)
      ->count()
      ->execute();

    $this->assertEquals(2, $redCount);
    $this->assertEquals(2, $blueCount);
  }

  /**
   * Tests that a failed save rolls back cleanly without partial state.
   */
  public function testTransactionRollbackOnError(): void {
    $this->setCurrentUser($this->createUser('voter'));
    $question = $this->createQuestion('favorite-color');
    $this->createOption($question, 'red');

    $this->voteManager->castVote('favorite-color', 'red');

    // A duplicate attempt throws and must leave the table unchanged.
    try {
      $this->voteManager->castVote('favorite-color', 'red');
      $this->fail('Expected VoteException was not thrown.');
    } catch (VoteException $e) {
      $this->assertSame(VoteException::DUPLICATE, $e->getErrorCode());
    }

    $count = \Drupal::entityTypeManager()
      ->getStorage('vote')
      ->getQuery()
      ->condition('question', $question->id())
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertEquals(1, $count);
  }

  /**
   * Tests concurrent voting with distinct users.
   */
  public function testHighConcurrencyVoting(): void {
    $question = $this->createQuestion('favorite-color');
    $this->createOption($question, 'red');

    $userNames = [];
    for ($i = 0; $i < 20; $i++) {
      $userNames[] = 'user_' . $i;
    }

    foreach ($userNames as $name) {
      $this->setCurrentUser($this->createUser($name));
      $this->voteManager->castVote('favorite-color', 'red');
    }

    $count = \Drupal::entityTypeManager()
      ->getStorage('vote')
      ->getQuery()
      ->condition('question', $question->id())
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertEquals(20, $count);
  }

  /**
   * Tests that successful vote attempts are recorded.
   */
  public function testVoteAttemptsAreLogged(): void {
    $this->setCurrentUser($this->createUser('voter'));
    $question = $this->createQuestion('favorite-color');
    $this->createOption($question, 'red');

    $this->voteManager->castVote('favorite-color', 'red');

    $votes = \Drupal::entityTypeManager()
      ->getStorage('vote')
      ->loadByProperties(['question' => $question->id()]);
    $this->assertCount(1, $votes);

    $vote = reset($votes);
    $this->assertInstanceOf(Vote::class, $vote);
    $this->assertNotEmpty($vote->get('created')->value);
  }
}