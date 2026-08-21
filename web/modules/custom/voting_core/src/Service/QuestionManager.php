<?php

declare(strict_types=1);

namespace Drupal\voting_core\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;
use Drupal\voting_core\Entity\Question;

/**
 * Provides high-level operations for Questions.
 *
 * This service encapsulates business logic for:
 * - Retrieving questions for API responses
 * - Aggregating and formatting vote results
 * - Normalizing entity data for external consumption.
 */
final class QuestionManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
    private readonly CacheBackendInterface $cacheBackend,
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Returns a list of active questions formatted for API responses.
   *
   * This method:
   * - Queries only active questions (status = TRUE)
   * - Loads associated options for each question
   * - Normalizes data into a consistent API format.
   *
   * @return array<int, array<string, mixed>>
   *   A structured array ready to be encoded as JSON.
   *   Format: [
   *     [
   *       'identifier' => 'string',
   *       'title' => 'string',
   *       'description' => 'string',
   *       'show_results' => bool,
   *       'options' => [...]
   *     ],
   *     ...
   *   ]
   */
  public function getActiveQuestionsForApi(): array {
    // Implement caching to reduce DB load for frequent API calls.
    $cacheKey = 'voting_core:active_questions_normalized';
    $cached = $this->cacheBackend->get($cacheKey);

    if ($cached !== FALSE) {
      $this->logger->debug('Active questions cache HIT');
      return $cached->data;
    }

    $this->logger->debug('Active questions cache MISS');

    // Load only active questions.
    $questionStorage = $this->entityTypeManager->getStorage('question');
    $questions = $questionStorage->loadByProperties(
      [
        'status' => TRUE,
      ]
    );

    $result = [];
    foreach ($questions as $question) {
      /** @var \Drupal\voting_core\Entity\Question $question */
      $result[] = $this->normalizeQuestion($question, FALSE);
    }

    // Cache the result for 1 hour with appropriate tags.
    $this->cacheBackend->set(
      $cacheKey,
      $result,
      time() + 3600,
      ['question_list']
    );

    return $result;
  }

  /**
   * Returns a single question formatted for API responses.
   *
   * @param string $identifier
   *   The question identifier.
   *
   * @return array<string, mixed>|null
   *   Normalized question or NULL if not found/inactive.
   */
  public function getQuestionForApi(string $identifier): ?array {
    // Implement caching to reduce DB load for frequent API calls.
    $cacheKey = "voting_core:question:{$identifier}";
    $cached = $this->cacheBackend->get($cacheKey);

    if ($cached !== FALSE) {
      return $cached->data;
    }

    $questionStorage = $this->entityTypeManager->getStorage('question');
    $questions = $questionStorage->loadByProperties(
      [
        'identifier' => $identifier,
      ]
    );
    $question = reset($questions) ?: NULL;
    /** @var \Drupal\voting_core\Entity\Question|null $question */

    // Return NULL if question doesn't exist or is inactive.
    if ($question === NULL || (bool) $question->get('status')->value === FALSE) {
      return NULL;
    }

    $result = $this->normalizeQuestion($question, TRUE);

    // Cache the result for 30 minutes with appropriate tags.
    $this->cacheBackend->set(
      $cacheKey,
      $result,
      time() + 1800,
      ['question:' . $question->id()]
    );

    return $result;
  }

  /**
   * Returns results for a question formatted for API responses.
   *
   * @param string $identifier
   *   The question identifier.
   *
   * @return array<string, mixed>|null
   *   Array with 'question', 'results', 'total_votes' or NULL.
   */
  public function getResultsForApi(string $identifier): ?array {
    $cacheKey = "voting_core:results:$identifier";
    $cached = $this->cacheBackend->get($cacheKey);

    if ($cached) {
      $this->logger->debug('Results served from cache', [
        'question_identifier' => $identifier,
      ]);
      return $cached->data;
    }

    // Load question.
    $questionStorage = $this->entityTypeManager->getStorage('question');
    $questions = $questionStorage->loadByProperties(['identifier' => $identifier]);
    $question = reset($questions) ?: NULL;
    /** @var \Drupal\voting_core\Entity\Question|null $question */

    if ($question === NULL || (bool) $question->get('status')->value === FALSE) {
      return NULL;
    }

    $showResults = (bool) $question->get('show_results')->value;
    if (!$showResults) {
      $this->logger->notice('Results request denied for question with show_results=FALSE', [
        'question_identifier' => $identifier,
      ]);
      return NULL;
    }

    $optionStorage = $this->entityTypeManager->getStorage('option');

    $options = $optionStorage->loadByProperties([
      'question' => $question->id(),
    ]);

    // Single aggregated query (GROUP BY option) instead of N COUNT queries.
    $countQuery = $this->database->select('voting_vote', 'v');
    $countQuery->fields('v', ['option']);
    $countQuery->addExpression('COUNT(*)', 'vote_count');
    $countQuery->condition('v.question', $question->id());
    $countQuery->groupBy('v.option');
    $counts = $countQuery->execute()->fetchAllKeyed();

    $results = [];
    $totalVotes = 0;

    foreach ($options as $option) {
      /** @var \Drupal\voting_core\Entity\Option $option */
      $optionId = $option->id();
      $count = (int) ($counts[$optionId] ?? 0);

      $totalVotes += $count;

      $results[] = [
        'option_id' => $optionId,
        'option_identifier' => $option->get('identifier')->value,
        'option_title' => $option->get('title')->value,
        'vote_count' => $count,
      ];
    }

    foreach ($results as &$result) {
      $result['percentage'] = $totalVotes > 0
        ? round(($result['vote_count'] / $totalVotes) * 100, 2)
        : 0.0;
    }

    $data = [
      'question_id' => (int) $question->id(),
      'question' => $this->normalizeQuestion($question, FALSE),
      'results' => $results,
      'total_votes' => $totalVotes,
    ];

    $ttl = (int) ($this->configFactory->get('voting_core.settings')->get('cache_results_ttl') ?? 300);

    // Cache the results with appropriate tags.
    $this->cacheBackend->set(
      $cacheKey,
      $data,
      time() + max(0, $ttl),
      [
        'question:' . $question->id(),
        'question_results',
      ]
    );

    $this->logger->debug('Results calculated and cached', [
      'question_identifier' => $identifier,
      'total_votes' => $totalVotes,
    ]);

    return $data;
  }

  /**
   * Normalizes a question entity into an array for API consumption.
   *
   * @param \Drupal\voting_core\Entity\Question $question
   *   The question entity to normalize.
   * @param bool $includeOptions
   *   Whether to include options.
   *
   * @return array<string, mixed>
   *   Normalized question data.
   */
  private function normalizeQuestion(Question $question, bool $includeOptions = TRUE): array {
    $data = [
      'identifier' => $question->get('identifier')->value,
      'title' => $question->get('title')->value,
      'description' => $question->get('description')->value,
      'show_results' => (bool) $question->get('show_results')->value,
      'created' => $question->get('created')->value,
      'changed' => $question->get('changed')->value,
    ];

    if ($includeOptions) {
      $data['options'] = $this->loadOptionsForQuestion((int) $question->id());
    }

    return $data;
  }

  /**
   * Loads and normalizes options for a given question.
   *
   * @param int $questionId
   *   The question entity ID.
   *
   * @return array<int, array<string, mixed>>
   *   Indexed array of normalized options.
   */
  private function loadOptionsForQuestion(int $questionId): array {
    $optionStorage = $this->entityTypeManager->getStorage('option');

    // Load options for this question, ordered by weight.
    $query = $optionStorage->getQuery()
      ->condition('question', $questionId)
      ->sort('weight', 'ASC')
      // Admin operation, not user-facing.
      ->accessCheck(FALSE);

    $optionIds = $query->execute();
    $options = $optionStorage->loadMultiple($optionIds);

    $result = [];
    foreach ($options as $option) {
      /** @var \Drupal\voting_core\Entity\Option $option */
      $optionData = [
        'identifier' => $option->get('identifier')->value,
        'title' => $option->get('title')->value,
        'description' => $option->get('description')->value,
        'weight' => (int) $option->get('weight')->value,
      ];

      // Include image URL if present.
      // isEmpty() avoids errors if image field is NULL.
      if (!$option->get('image')->isEmpty()) {
        /** @var \Drupal\file\FileInterface $file */
        $file = $option->get('image')->entity;
        if ($file instanceof FileInterface) {
          $optionData['image_url'] = $file->createFileUrl(FALSE);
        }
      }

      $result[] = $optionData;
    }

    return $result;
  }
}
