<?php

declare(strict_types=1);

namespace Drupal\voting_core\Event;

use Drupal\voting_core\Entity\Vote;
use Drupal\voting_core\Entity\Question;
use Drupal\voting_core\Entity\Option;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched after a vote has been successfully recorded.
 *
 * GOAL:
 * - Decouple external sync / analytics / notifications from core vote logic.
 */
final class VoteEvent extends Event {

  /**
   * The event name dispatched when a vote is cast.
   */
  public const NAME = 'voting_core.vote.cast';

  /**
   * Constructs a new VoteEvent.
   *
   * @param \Drupal\voting_core\Entity\Vote $vote
   *   The vote entity.
   * @param \Drupal\voting_core\Entity\Question $question
   *   The question entity.
   * @param \Drupal\voting_core\Entity\Option $option
   *   The selected option entity.
   */
  public function __construct(
    private readonly Vote $vote,
    private readonly Question $question,
    private readonly Option $option,
  ) {
  }

  /**
   * Gets the vote entity.
   */
  public function getVote(): Vote {
    return $this->vote;
  }

  /**
   * Gets the question entity.
   */
  public function getQuestion(): Question {
    return $this->question;
  }

  /**
   * Gets the option entity.
   */
  public function getOption(): Option {
    return $this->option;
  }

  /**
   * Convenience: user ID.
   */
  public function getUserId(): int {
    return (int) $this->vote->get('user_id')->target_id;
  }

  /**
   * Convenience: timestamp.
   *
   * Returns the vote creation timestamp based on the "created" field.
   * Falls back to the current request time if the field is empty.
   */
  public function getTimestamp(): int {
    $created = $this->vote->get('created')->value;

    // Fallback to current time if "created" is NULL.
    if ($created === NULL) {
      return \Drupal::time()->getRequestTime();
    }

    return (int) $created;
  }
}
