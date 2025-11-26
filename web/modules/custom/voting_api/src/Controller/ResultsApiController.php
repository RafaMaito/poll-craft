<?php

declare(strict_types=1);

namespace Drupal\voting_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\voting_core\Service\QuestionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST API controller for voting results.
 *
 * BUSINESS RULES:
 * 1. Results are only shown if question.show_results is TRUE
 * 2. Results include vote counts and percentages per option
 * 3. Results are read-only (no POST/PUT/DELETE)
 *
 * PRIVACY CONSIDERATIONS:
 * - Individual votes are never exposed (only aggregated counts)
 * - User identities are not included in results
 * - Administrators can disable results per question
 */
final class ResultsApiController extends ControllerBase {

  public function __construct(
    private readonly QuestionManager $questionManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('voting_core.question_manager'),
    );
  }

  /**
   * Retrieves voting results for a specific question.
   *
   * Endpoint: GET /api/voting/questions/{identifier}/results.
   * Example: GET /api/voting/questions/favorite-color/results
   *
   * Response format (success):
   * {
   *   "question": {
   *     "identifier": "favorite-color",
   *     "title": "What's your favorite color?",
   *     "description": "...",
   *     "show_results": TRUE
   *   },
   *   "results": [
   *     {
   *       "option_id": 1,
   *       "option_identifier": "red",
   *       "option_title": "Red",
   *       "vote_count": 150,
   *       "percentage": 45.45
   *     },
   *     {
   *       "option_id": 2,
   *       "option_identifier": "blue",
   *       "option_title": "Blue",
   *       "vote_count": 100,
   *       "percentage": 30.30
   *     },
   *     {
   *       "option_id": 3,
   *       "option_identifier": "green",
   *       "option_title": "Green",
   *       "vote_count": 80,
   *       "percentage": 24.24
   *     }
   *   ],
   *   "total_votes": 330
   * }
   *
   * Response format (not allowed):
   * {
   *   "error": "Results are not available for this question."
   * }
   *
   * HTTP Status Codes:
   * - 200 OK: Results returned successfully
   * - 403 Forbidden: Results are disabled for this question
   * - 404 Not Found: Question doesn't exist or is inactive
   * - 500 Internal Server Error: System error
   *
   * CACHING STRATEGY:
   * Results can be cached aggressively since they change infrequently:
   * - Cache for 5-10 minutes on high-traffic sites
   * - Invalidate cache when new votes are cast
   * - Use Drupal's Cache API or Redis for production
   *
   * @param string $identifier
   *   The question identifier from URL parameter.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with results or error.
   */
  public function getResults(string $identifier): JsonResponse {
    try {
      // Fetch results using the QuestionManager service.
      $results = $this->questionManager->getResultsForApi($identifier);
      if ($results === NULL) {
        return new JsonResponse([
          'error' => 'Results are not available for this question.',
        ], Response::HTTP_FORBIDDEN);
      }

      return new JsonResponse($results, Response::HTTP_OK);
    }
    catch (\Exception $e) {
      $this->getLogger('voting_api')->error('Error retrieving results: @message', [
        '@message' => $e->getMessage(),
        'identifier' => $identifier,
        'exception' => $e,
      ]);

      return new JsonResponse([
        'error' => 'An error occurred while retrieving results.',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

}
