<?php

declare(strict_types=1);

namespace Drupal\voting_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\voting_core\Service\VoteManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST API controller for voting operations.
 *
 * SECURITY CONSIDERATIONS:
 * 1. Validates JSON payload structure
 * 2. Sanitizes input (Drupal handles this at entity level)
 * 3. Rate limiting should be added in production (see services.yml)
 * 4. All business rules enforced in VoteManager service
 * 5. Returns appropriate HTTP status codes for different error types.
 *
 * ERROR HANDLING STRATEGY:
 * - 400 Bad Request: Invalid input format
 * - 403 Forbidden: User not allowed (anonymous, already voted)
 * - 404 Not Found: Question or option doesn't exist
 * - 409 Conflict: Duplicate vote attempt
 * - 500 Internal Server Error: System error
 */
final class VoteApiController extends ControllerBase {

  /**
   * Constructs a VoteApiController object.
   */
  public function __construct(
    private readonly VoteManager $voteManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('voting_core.vote_manager'),
    );
  }

  /**
   * Casts a vote for a voting question.
   *
   * Endpoint: POST /api/voting/vote.
   *
   * Request body (JSON):
   * {
   *   "question_identifier": "favorite-color",
   *   "option_identifier": "red"
   * }
   *
   * Response format (success):
   * {
   *   "message": "Vote registered successfully.",
   *   "question_identifier": "favorite-color",
   *   "option_identifier": "red"
   * }
   *
   * Response format (error):
   * {
   *   "error": "You have already voted for this question."
   * }
   *
   * HTTP Status Codes:
   * - 200 OK: Vote registered successfully
   * - 400 Bad Request: Invalid JSON or missing required fields
   * - 403 Forbidden: voting disabled or user not allowed
   * - 404 Not Found: Question or option doesn't exist
   * - 409 Conflict: User already voted for this question
   * - 500 Internal Server Error: System error
   *
   * IMPORTANT: This endpoint requires user authentication.
   * API authentication improvements could include:
   * - OAuth 2.0 for third-party apps
   * - JWT tokens for mobile apps
   * - API keys for trusted clients
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object containing JSON payload.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with success message or error.
   */
  public function castVote(Request $request): JsonResponse {
    // Parse and validate JSON payload.
    $content = $request->getContent();

    if (empty($content)) {
      return new JsonResponse([
        'error' => 'Empty request body. Expected JSON with question_identifier and option_identifier.',
      ], Response::HTTP_BAD_REQUEST);
    }

    try {
      $data = json_decode($content, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      return new JsonResponse([
        'error' => 'Invalid JSON format.',
      ], Response::HTTP_BAD_REQUEST);
    }

    // Validate required fields.
    $questionIdentifier = $data['question_identifier'] ?? NULL;
    $optionIdentifier = $data['option_identifier'] ?? NULL;

    if (empty($questionIdentifier) || empty($optionIdentifier)) {
      return new JsonResponse([
        'error' => 'Missing required fields: question_identifier and option_identifier.',
        'received' => [
          'question_identifier' => $questionIdentifier !== NULL,
          'option_identifier' => $optionIdentifier !== NULL,
        ],
      ], Response::HTTP_BAD_REQUEST);
    }

    // Validate data types and length.
    if (!is_string($questionIdentifier) || strlen($questionIdentifier) > 128) {
      return new JsonResponse([
        'error' => 'Invalid question_identifier. Must be a string with max 128 characters.',
      ], Response::HTTP_BAD_REQUEST);
    }

    if (!is_string($optionIdentifier) || strlen($optionIdentifier) > 128) {
      return new JsonResponse([
        'error' => 'Invalid option_identifier. Must be a string with max 128 characters.',
      ], Response::HTTP_BAD_REQUEST);
    }

    // Delegate to VoteManager and handle business rules.
    try {
      $this->voteManager->castVote($questionIdentifier, $optionIdentifier);

      // Success! Return 200 OK with confirmation.
      return new JsonResponse([
        'message' => 'Vote registered successfully.',
        'question_identifier' => $questionIdentifier,
        'option_identifier' => $optionIdentifier,
      ], Response::HTTP_OK);
    }
    catch (\RuntimeException $e) {
      // Business rule violations from VoteManager.
      // Map error messages to appropriate HTTP status codes.
      $errorMessage = $e->getMessage();

      // Determine the appropriate HTTP status code,
      // based on error message.
      $statusCode = match (TRUE) {
        str_contains($errorMessage, 'disabled') => Response::HTTP_FORBIDDEN,
        str_contains($errorMessage, 'not allowed') => Response::HTTP_FORBIDDEN,
        str_contains($errorMessage, 'already voted') => Response::HTTP_CONFLICT,
        str_contains($errorMessage, 'not found') => Response::HTTP_NOT_FOUND,
        str_contains($errorMessage, 'inactive') => Response::HTTP_NOT_FOUND,
        str_contains($errorMessage, 'Invalid option') => Response::HTTP_NOT_FOUND,
        default => Response::HTTP_INTERNAL_SERVER_ERROR,
      };

      return new JsonResponse([
        'error' => $errorMessage,
      ], $statusCode);
    }
    catch (\Exception $e) {
      // Unexpected system errors.
      $this->getLogger('voting_api')->error('Unexpected error during vote casting: @message', [
        '@message' => $e->getMessage(),
        'question_identifier' => $questionIdentifier,
        'option_identifier' => $optionIdentifier,
        'exception' => $e,
      ]);

      return new JsonResponse([
        'error' => 'An unexpected error occurred. Please try again.',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

}
