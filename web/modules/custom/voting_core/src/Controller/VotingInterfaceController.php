<?php

declare(strict_types=1);

namespace Drupal\voting_core\Controller;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\voting_core\Service\QuestionManager;
use Drupal\voting_core\Service\VoteManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for user-facing voting interface.
 *
 * ARCHITECTURAL NOTE:
 * Vote submission is handled in votingForm::submitForm(),
 * not here. This follows Drupal best practices where forms
 * process their own submissions.
 */
class VotingInterfaceController extends ControllerBase {

  public function __construct(
    private readonly QuestionManager $questionManager,
    private readonly VoteManager $voteManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('voting_core.question_manager'),
      $container->get('voting_core.vote_manager'),
    );
  }

  /**
   * Lists all active questions.
   * Each question indicates if the user has voted.
   * Reads global config to check if voting is enabled.
   * Returns a render array for theming.
   */
public function listQuestions(): array {
  // Search for active questions.
  $questions = $this->questionManager->getActiveQuestionsForApi();

  foreach ($questions as &$question) {
    $question['has_voted'] = $this->voteManager->hasUserVoted($question['identifier']);
  }

  // Read global config.
  $votingEnabled = (bool) $this->config('voting_core.settings')
    ->get('voting_enabled');

  return [
    '#theme' => 'question_list',
    '#questions' => $questions,
    '#voting_enabled' => $votingEnabled,
    '#cache' => [
      'contexts' => ['user'],
      'tags' => [
        'question_list',
        'config:voting_core.settings',
      ],
      'max-age' => 300,
    ],
  ];
}

  /**
   * Displays a single question with voting form.
   * 
   * If user has already voted, shows results or message.
   */
  public function viewQuestion(string $identifier): array|RedirectResponse {
    $question = $this->questionManager->getQuestionForApi($identifier);

    if (!$question) {
      throw new NotFoundHttpException();
    }

    $hasVoted = $this->voteManager->hasUserVoted($identifier);

    // If already voted and results are enabled, show results.
    if ($hasVoted && $question['show_results']) {
      return $this->redirect('voting_core.view_results', ['identifier' => $identifier]);
    }

    // If already voted but results disabled, show message.
    if ($hasVoted && !$question['show_results']) {
      return [
        '#theme' => 'question_already_voted',
        '#question' => $question,
      ];
    }

    // Show voting form.
    return [
      '#theme' => 'question_voting_form',
      '#question' => $question,
      '#form' => $this->formBuilder()->getForm(
        'Drupal\voting_core\Form\VotingForm',
        $identifier
      ),
    ];
  }

  /**
   * Displays results for a question.
   * 
   * If results are not available, 
   * redirects back to question list.
   */
  public function viewResults(string $identifier): array|RedirectResponse {
    $results = $this->questionManager->getResultsForApi($identifier);

    if (!$results) {
      $this->messenger()->addWarning(
        $this->t('Results are not available for this question.')
      );
      return $this->redirect('voting_core.question_list');
    }

    return [
      '#theme' => 'question_results',
      '#results' => $results,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['question:' . $identifier],
        'max-age' => 300,
      ],
    ];
  }
}
