<?php

declare(strict_types=1);

namespace Drupal\Tests\voting_core\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\voting_core\Entity\Option;
use Drupal\voting_core\Entity\Question;
use Drupal\voting_core\Entity\Vote;

/**
 * Kernel tests for the voting entities.
 *
 * @group voting_core
 */
final class VotingEntitiesTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('question');
    $this->installEntitySchema('option');
    $this->installEntitySchema('vote');
    $this->installConfig(['voting_core']);

    // Apply the unique constraints that _voting_core_ensure_indexes() would
    // add in a real install (hook_install / update hook).
    $schema = $this->container->get('database')->schema();
    if (!$schema->indexExists('voting_question', 'identifier')) {
      $schema->addUniqueKey('voting_question', 'identifier', ['identifier']);
    }
    if (!$schema->indexExists('voting_vote', 'question_user_unique')) {
      $schema->addUniqueKey('voting_vote', 'question_user_unique', ['question', 'user_id']);
    }
  }

  /**
   * Creates a question with the given identifier.
   */
  private function createQuestion(string $identifier, bool $status = TRUE, bool $showResults = TRUE): Question {
    $question = Question::create([
      'identifier' => $identifier,
      'title' => 'Question ' . $identifier,
      'status' => $status ? 1 : 0,
      'show_results' => $showResults ? 1 : 0,
    ]);
    $question->save();
    return $question;
  }

  /**
   * Creates an option for the given question.
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
   * Tests question entity creation.
   */
  public function testQuestionEntityCreation(): void {
    $question = $this->createQuestion('q1');

    $this->assertEquals('q1', $question->get('identifier')->value);
    $this->assertEquals('Question q1', $question->get('title')->value);
    $this->assertTrue((bool) $question->get('status')->value);
  }

  /**
   * Tests that the question identifier is unique.
   */
  public function testQuestionIdentifierUniqueness(): void {
    $this->createQuestion('duplicate');

    $this->expectException(EntityStorageException::class);
    $this->createQuestion('duplicate');
  }

  /**
   * Tests the option/question relationship.
   */
  public function testOptionEntityWithQuestionRelationship(): void {
    $question = $this->createQuestion('q1');
    $option = $this->createOption($question, 'opt1');

    $this->assertEquals($question->id(), $option->get('question')->target_id);
    $this->assertEquals('opt1', $option->get('identifier')->value);
  }

  /**
   * Tests vote entity user tracking.
   */
  public function testVoteEntityWithUserTracking(): void {
    $user = User::create(['name' => 'voter', 'status' => 1]);
    $user->save();

    $question = $this->createQuestion('q1');
    $option = $this->createOption($question, 'opt1');

    $vote = Vote::create([
      'question' => $question->id(),
      'option' => $option->id(),
      'user_id' => $user->id(),
    ]);
    $vote->save();

    $this->assertEquals($user->id(), $vote->get('user_id')->target_id);
    $this->assertEquals($question->id(), $vote->get('question')->target_id);
  }

  /**
   * Tests duplicate vote prevention at the database level.
   */
  public function testDuplicateVotePreventionViaDatabase(): void {
    $user = User::create(['name' => 'voter', 'status' => 1]);
    $user->save();

    $question = $this->createQuestion('q1');
    $option = $this->createOption($question, 'opt1');

    $vote = Vote::create([
      'question' => $question->id(),
      'option' => $option->id(),
      'user_id' => $user->id(),
    ]);
    $vote->save();

    $duplicate = Vote::create([
      'question' => $question->id(),
      'option' => $option->id(),
      'user_id' => $user->id(),
    ]);

    $this->expectException(EntityStorageException::class);
    $duplicate->save();
  }

  /**
   * Tests loading a question by its identifier.
   */
  public function testLoadQuestionByIdentifier(): void {
    $question = $this->createQuestion('q1');

    $loaded = \Drupal::entityTypeManager()
      ->getStorage('question')
      ->loadByProperties(['identifier' => 'q1']);

    $this->assertCount(1, $loaded);
    $this->assertEquals($question->id(), reset($loaded)->id());
  }

  /**
   * Tests vote counting aggregation.
   */
  public function testVoteCountingAggregation(): void {
    $question = $this->createQuestion('q1');
    $option = $this->createOption($question, 'opt1');

    foreach (['u1', 'u2'] as $name) {
      $user = User::create(['name' => $name, 'status' => 1]);
      $user->save();
      $vote = Vote::create([
        'question' => $question->id(),
        'option' => $option->id(),
        'user_id' => $user->id(),
      ]);
      $vote->save();
    }

    $count = \Drupal::entityTypeManager()
      ->getStorage('vote')
      ->getQuery()
      ->condition('option', $option->id())
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertEquals(2, $count);
  }

  /**
   * Tests that inactive questions are filtered from the active list.
   */
  public function testInactiveQuestionsAreFiltered(): void {
    $this->createQuestion('active', TRUE);
    $this->createQuestion('inactive', FALSE);

    $questionManager = $this->container->get('voting_core.question_manager');
    $questions = $questionManager->getActiveQuestionsForApi();

    $this->assertCount(1, $questions);
    $this->assertEquals('active', $questions[0]['identifier']);
  }

  /**
   * Tests that deleting a question cascades to its options.
   */
  public function testCascadeDeleteOfOptions(): void {
    $question = $this->createQuestion('q1');
    $this->createOption($question, 'opt1');
    $this->createOption($question, 'opt2');

    $question->delete();

    $options = \Drupal::entityTypeManager()
      ->getStorage('option')
      ->loadByProperties(['question' => $question->id()]);
    $this->assertCount(0, $options);
  }

  /**
   * Tests that options are ordered by weight.
   */
  public function testOptionsOrderedByWeight(): void {
    $question = $this->createQuestion('q1');
    $this->createOption($question, 'third', 3);
    $this->createOption($question, 'first', 1);
    $this->createOption($question, 'second', 2);

    $ids = \Drupal::entityTypeManager()
      ->getStorage('option')
      ->getQuery()
      ->condition('question', $question->id())
      ->sort('weight', 'ASC')
      ->accessCheck(FALSE)
      ->execute();

    $options = \Drupal::entityTypeManager()->getStorage('option')->loadMultiple($ids);
    $identifiers = array_map(
      static fn(Option $option): string => $option->get('identifier')->value,
      array_values($options)
    );

    $this->assertEquals(['first', 'second', 'third'], $identifiers);
  }

  /**
   * Tests that timestamps are automatically set.
   */
  public function testTimestampsAreAutomaticallySet(): void {
    $question = $this->createQuestion('q1');

    $this->assertNotEmpty($question->get('created')->value);
    $this->assertNotEmpty($question->get('changed')->value);
  }
}
