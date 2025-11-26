<?php

declare(strict_types=1);

namespace Drupal\voting_core\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\voting_core\Service\QuestionManager;
use Drupal\voting_core\Service\VoteManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a voting form for questions.
 * This form handles:
 * - Displaying the voting options for a question.
 * - Validating user input.
 * - Submitting the vote and recording it.
 */
final class VotingForm extends FormBase {

  /**
   * The vote manager service.
   *
   * @var \Drupal\voting_core\Service\VoteManager
   */
  protected VoteManager $voteManager;

  /**
   * The question manager service.
   *
   * @var \Drupal\voting_core\Service\QuestionManager
   */
  protected QuestionManager $questionManager;

  /**
   * Constructs a VotingForm object.
   *
   * @param \Drupal\voting_core\Service\VoteManager $vote_manager
   *   The vote manager service.
   * @param \Drupal\voting_core\Service\QuestionManager $question_manager
   *   The question manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    VoteManager $vote_manager,
    QuestionManager $question_manager,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->voteManager = $vote_manager;
    $this->questionManager = $question_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * Creates an instance of the form.
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('voting_core.vote_manager'),
      $container->get('voting_core.question_manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * Gets the form ID.
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'voting_core_voting_form';
  }

  /**
   * Builds the voting form.
   * @param array $form
   *  The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * The form state.
   * @param string|null $question_identifier
   *  The question identifier from the route.
   * @return array
   *  The built form array.
   * 
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $question_identifier = NULL): array {
    // Check if voting is globally enabled
    $config = $this->configFactory->get('voting_core.settings');
    $voting_enabled = $config->get('voting_enabled');

    // If voting is disabled, show message instead of form.
    if ($voting_enabled === FALSE) {
      $form['disabled_message'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['voting-system-disabled']],
      ];

      $form['disabled_message']['message'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('The voting system is currently disabled by the administrator. Please check back later.'),
        '#attributes' => ['class' => ['voting-disabled-message']],
      ];

      $form['disabled_message']['back'] = [
        '#type' => 'link',
        '#title' => $this->t('← Back to questions'),
        '#url' => Url::fromRoute('voting_core.question_list'),
        '#attributes' => ['class' => ['button', 'button--secondary']],
      ];

      return $form;
    }

    // Load and validate question
    $question = $this->questionManager->getQuestionForApi($question_identifier);

    if (!$question) {
      $form['error'] = [
        '#markup' => '<p>' . $this->t('Question not found.') . '</p>',
      ];

      $form['back'] = [
        '#type' => 'link',
        '#title' => $this->t('← Back to questions'),
        '#url' => Url::fromRoute('voting_core.question_list'),
        '#attributes' => ['class' => ['button']],
      ];

      return $form;
    }

    // Store question identifier in form state.
    $form_state->set('question_identifier', $question_identifier);

    // Check if user already voted
    $has_voted = $this->voteManager->hasUserVoted($question_identifier);

    if ($has_voted) {
      $user_vote = $this->voteManager->getUserVote($question_identifier);

      // Build "already voted" message
      $form['already_voted'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['voting-already-voted']],
      ];

      $form['already_voted']['message'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('You have already voted on this question.'),
        '#attributes' => ['class' => ['voting-already-voted-message']],
      ];

      if ($user_vote) {
        // Find the chosen option title.
        $chosen_option_title = '';
        foreach ($question['options'] as $option) {
          if ($option['identifier'] === $user_vote) {
            $chosen_option_title = $option['title'];
            break;
          }
        }

        $form['already_voted']['choice'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Your choice: <strong>@option</strong>', [
            '@option' => $chosen_option_title,
          ]),
          '#attributes' => ['class' => ['voting-user-choice']],
        ];
      }

      if ($question['show_results']) {
        $form['already_voted']['results'] = [
          '#type' => 'link',
          '#title' => $this->t('View results'),
          '#url' => Url::fromRoute('voting_core.view_results', [
            'identifier' => $question_identifier,
          ]),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ];
      }

      $form['already_voted']['back'] = [
        '#type' => 'link',
        '#title' => $this->t('← Back to questions'),
        '#url' => Url::fromRoute('voting_core.question_list'),
        '#attributes' => ['class' => ['button', 'button--secondary']],
      ];

      return $form;
    }

    // Build voting form
    $form['question_title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $question['title'],
      '#attributes' => ['class' => ['question-title']],
    ];

    if (!empty($question['description'])) {
      $form['question_description'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $question['description'],
        '#attributes' => ['class' => ['question-description']],
      ];
    }

    $options = [];
    foreach ($question['options'] as $option) {
      $options[$option['identifier']] = $option['title'];
    }

    $form['option_identifier'] = [
      '#type' => 'radios',
      '#title' => $this->t('Choose your option'),
      '#options' => $options,
      '#required' => TRUE,
      '#attributes' => ['class' => ['voting-options']],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Vote'),
      '#button_type' => 'primary',
    ];

    $form['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('voting_core.question_list'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * Submit handler for the voting form.
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Double-check voting is enabled.
    $config = $this->configFactory->get('voting_core.settings');
    if ($config->get('voting_enabled') === FALSE) {
      $this->messenger()->addError(
        $this->t('Voting is currently disabled. Your vote was not recorded.')
      );
      $form_state->setRedirect('voting_core.question_list');
      return;
    }

    $question_identifier = $form_state->get('question_identifier');
    $option_identifier = $form_state->getValue('option_identifier');

    // Attempt to cast the vote.
    try {
      $this->voteManager->castVote($question_identifier, $option_identifier);

      $this->messenger()->addStatus(
        $this->t('Thank you for voting! Your vote has been recorded.')
      );

      // Redirect to results if enabled, otherwise to question list.
      $question = $this->questionManager->getQuestionForApi($question_identifier);
      if ($question && $question['show_results']) {
        $form_state->setRedirect('voting_core.view_results', [
          'identifier' => $question_identifier,
        ]);
      }
      else {
        $form_state->setRedirect('voting_core.question_list');
      }
    }
    catch (\RuntimeException $e) {
      $this->messenger()->addError(
        $this->t('An error occurred while recording your vote: @message', [
          '@message' => $e->getMessage(),
        ])
      );

      $form_state->setRebuild(TRUE);
    }
  }

}
