<?php

declare(strict_types=1);

namespace Drupal\voting_core\Commands;

use Drush\Commands\DrushCommands;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Drush commands for Voting Core.
 * 
 * Provides CLI utilities to manage and test the voting system.
 * @package Drupal\voting_core\Commands
 * 
 */
final class VotingCoreCommands extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Create sample questions with options.
   *
   * @command voting:create-questions
   * @aliases vcq
   *
   * @param int $num_questions
   *   Number of questions to create.
   * @param int $num_options
   *   Number of options per question.
   *
   * @usage drush vcq 10 4
   *   Creates 10 questions with 4 options each.
   */
  public function createQuestions(int $num_questions = 5, int $num_options = 3): void {
    $questionStorage = $this->entityTypeManager->getStorage('question');
    $optionStorage = $this->entityTypeManager->getStorage('option');

    for ($i = 1; $i <= $num_questions; $i++) {
      $identifier = 'question_' . $i;

      $question = $questionStorage->create([
        'identifier' => $identifier,
        'title' => "Pergunta $i",
        'description' => "Descrição da pergunta $i.",
        'status' => 1,
        'show_results' => 1,
      ]);
      $question->save();

      $this->io()->success("Question created: $identifier (ID {$question->id()})");

      for ($j = 1; $j <= $num_options; $j++) {
        $opt_identifier = "option_{$i}_{$j}";

        $option = $optionStorage->create([
          'question' => $question->id(),
          'identifier' => $opt_identifier,
          'title' => "Option $j for Question $i",
          'description' => "Description for option $j of question $i.",
          'weight' => $j,
        ]);
        $option->save();

        $this->io()->text("Option created: $opt_identifier");
      }

      $this->io()->newLine();
    }

    $this->io()->success("Done! Created $num_questions questions with $num_options options each.");
  }
}
