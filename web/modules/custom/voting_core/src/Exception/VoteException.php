<?php

declare(strict_types=1);

namespace Drupal\voting_core\Exception;

/**
 * Domain exception for vote business-rule violations.
 *
 * Carries a machine error code so API/UI layers can map it to the
 * appropriate HTTP status or user message without relying on string
 * matching of the message text.
 */
final class VoteException extends \RuntimeException {

  public const DISABLED = 'voting_disabled';
  public const ANONYMOUS_NOT_ALLOWED = 'anonymous_not_allowed';
  public const RATE_LIMIT = 'rate_limit';
  public const QUESTION_NOT_FOUND = 'question_not_found';
  public const QUESTION_INACTIVE = 'question_inactive';
  public const VOTING_CLOSED = 'voting_closed';
  public const INVALID_OPTION = 'invalid_option';
  public const DUPLICATE = 'duplicate_vote';

  /**
   * Constructs a VoteException.
   *
   * @param string $message
   *   Human-readable message.
   * @param string $errorCode
   *   Machine-readable error code (one of the class constants).
   * @param \Throwable|null $previous
   *   The previous exception, if any.
   */
  public function __construct(
    string $message,
    private readonly string $errorCode = self::DUPLICATE,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

  /**
   * Gets the machine-readable error code.
   */
  public function getErrorCode(): string {
    return $this->errorCode;
  }

}
