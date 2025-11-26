<?php

declare(strict_types=1);

namespace Drupal\voting_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\voting_core\Service\QuestionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST API controller for voting questions.
 *
 * DESIGN PRINCIPLES:
 * 1. Controller is THIN - all business logic is in QuestionManager service
 * 2. Dependency Injection via create() method (Drupal best practice)
 * 3. Type hints everywhere for PHP 8+ strict typing
 * 4. Consistent JSON response format
 * 5. Proper HTTP status codes (200, 404, etc.)
 *
 */
final class QuestionApiController extends ControllerBase {

  /**
   * Constructs a QuestionApiController object.
   */
  public function __construct(
    private readonly QuestionManager $questionManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('voting_core.question_manager'),
    );
  }

  /**
   * Lists all active voting questions.
   *
   * Endpoint: GET /api/voting/questions.
   *
   * Response format:
   * {
   *   "questions": [
   *     {
   *       "identifier": "favorite-color",
   *       "title": "What's your favorite color?",
   *       "description": "Choose your favorite color",
   *       "show_results": TRUE,
   *       "options": [...]
   *     },
   *     ...
   *   ]
   * }
   *
   * HTTP Status Codes:
   * - 200 OK: Success (even if array is empty)
   * - 500 Internal Server Error: Unexpected error
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with list of questions.
   */
  public function listQuestions(): JsonResponse {
    try {
      $questions = $this->questionManager->getActiveQuestionsForApi();

      return new JsonResponse([
        'questions' => $questions,
      ], Response::HTTP_OK);
    } catch (\Exception $e) {
      // Log the error for debugging.
      $this->getLogger('voting_api')->error('Error listing questions: @message', [
        '@message' => $e->getMessage(),
        'exception' => $e,
      ]);

      // Return generic error to client (don't expose internal details).
      return new JsonResponse([
        'error' => 'An error occurred while retrieving questions.',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Retrieves a specific voting question by identifier.
   *
   * Endpoint: GET /api/voting/questions/{identifier}
   * Example: GET /api/voting/questions/favorite-color
   *
   * Response format (success):
   * {
   *   "question": {
   *     "identifier": "favorite-color",
   *     "title": "What's your favorite color?",
   *     "description": "...",
   *     "show_results": TRUE,
   *     "options": [
   *       {
   *         "identifier": "red",
   *         "title": "Red",
   *         "description": "The color of passion",
   *         "image_url": "https://example.com/files/red.jpg"
   *       },
   *       ...
   *     ]
   *   }
   * }
   *
   * Response format (not found):
   * {
   *   "error": "Question not found or inactive."
   * }
   *
   * HTTP Status Codes:
   * - 200 OK: Question found and returned
   * - 404 Not Found: Question doesn't exist or is inactive
   * - 500 Internal Server Error: Unexpected error
   *
   * @param string $identifier
   *   The question identifier from the URL parameter.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with question details or error.
   */
  public function getQuestion(string $identifier): JsonResponse {
    try {
      // Fetch the question using the QuestionManager service.
      $question = $this->questionManager->getQuestionForApi($identifier);
      if ($question === NULL) {
        return new JsonResponse([
          'error' => 'Question not found or inactive.',
        ], Response::HTTP_NOT_FOUND);
      }

      return new JsonResponse([
        'question' => $question,
      ], Response::HTTP_OK);
    } catch (\Exception $e) {
      $this->getLogger('voting_api')->error('Error retrieving question: @message', [
        '@message' => $e->getMessage(),
        'identifier' => $identifier,
        'exception' => $e,
      ]);

      return new JsonResponse([
        'error' => 'An error occurred while retrieving the question.',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }
}
