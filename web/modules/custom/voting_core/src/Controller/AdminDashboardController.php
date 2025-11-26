<?php

declare(strict_types=1);

namespace Drupal\voting_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin dashboard controller for the voting system.
 *
 * This controller provides a centralized and visual interface for
 * administrators to manage the entire voting system.
 */
final class AdminDashboardController extends ControllerBase
{

    /**
     * The current user service.
     *
     * @var \Drupal\Core\Session\AccountProxyInterface
     */
    protected AccountProxyInterface $currentUserService;

    /**
     * Constructor with dependency injection.
     *
     * IMPORTANT: We do not redeclare $entityTypeManager because ControllerBase
     * already has this property (non-readonly). Using readonly would cause an error.
     *
     * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
     *   The entity type manager.
     * @param \Drupal\Core\Session\AccountProxyInterface     $current_user
     *   The current user service.
     */
    public function __construct(
        EntityTypeManagerInterface $entity_type_manager,
        AccountProxyInterface $current_user,
    ) {
// ControllerBase already defines $entityTypeManager.
        $this->entityTypeManager = $entity_type_manager;
        $this->currentUserService = $current_user;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container): self
    {
        return new self(
            $container->get('entity_type.manager'),
            $container->get('current_user'),
        );
    }

    /**
     * Main dashboard page.
     *
     * Displays:
     * - Personalized welcome message
     * - General statistics (questions, options, votes)
     * - Quick action blocks (create question, create option, settings)
     * - Lista de perguntas recentes com status visual
     * - Configurações globais em destaque
     *
     * @return array
     *   Render array do dashboard.
     */
    public function dashboard(): array
    {
        // Load statistics
        $stats = $this->getStatistics();

        // Load recent questions
        $recentQuestions = $this->getRecentQuestions(5);

        // Load global settings
        $settings = $this->config('voting_core.settings');
        $votingEnabled = $settings->get('voting_enabled') ?? true;

        // User name for greeting
        $userName = $this->currentUserService->getDisplayName();

        return [
        '#theme' => 'admin_dashboard',
        '#user_name' => $userName,
        '#statistics' => $stats,
        '#recent_questions' => $recentQuestions,
        '#voting_enabled' => $votingEnabled,
        '#quick_actions' => $this->buildQuickActions(),
        '#cache' => [
        'contexts' => ['user'],
        'tags' => ['question_list', 'option_list', 'config:voting_core.settings'],
        'max-age' => 300,
        ],
        ];
    }

    /**
     * Collects general system statistics.
     *
     * @return array
     *   Array with counts of questions, options, and votes.
     */
    private function getStatistics(): array
    {
        $questionStorage = $this->entityTypeManager->getStorage('question');
        $optionStorage = $this->entityTypeManager->getStorage('option');
        $voteStorage = $this->entityTypeManager->getStorage('vote');

        // Total counters
        $totalQuestions = count($questionStorage->loadMultiple());
        $activeQuestions = count($questionStorage->loadByProperties(['status' => true]));
        $totalOptions = count($optionStorage->loadMultiple());
        $totalVotes = count($voteStorage->loadMultiple());

        // Votes today
        $todayStart = strtotime('today');
        $votesToday = count(
            $voteStorage->getQuery()
                ->condition('created', $todayStart, '>=')
                ->accessCheck(false)
                ->execute()
        );

        return [
        'total_questions' => $totalQuestions,
        'active_questions' => $activeQuestions,
        'inactive_questions' => $totalQuestions - $activeQuestions,
        'total_options' => $totalOptions,
        'total_votes' => $totalVotes,
        'votes_today' => $votesToday,
        ];
    }

    /**
     * Loads recent questions with formatted information.
     *
     * @param int $limit
     *   NNumber of questions to load.
     *
     * @return array
     *   Array of formatted questions.
     */
    private function getRecentQuestions(int $limit = 5): array
    {
        $storage = $this->entityTypeManager->getStorage('question');
        $optionStorage = $this->entityTypeManager->getStorage('option');
        $voteStorage = $this->entityTypeManager->getStorage('vote');

        // Load recent questions
        $query = $storage->getQuery()
            ->sort('created', 'DESC')
            ->range(0, $limit)
            ->accessCheck(false);

        $ids = $query->execute();

        if (empty($ids)) {
            return [];
        }

        $questions = $storage->loadMultiple($ids);
        $formatted = [];

        foreach ($questions as $question) {
            $questionId = (int) $question->id();

            // Count options
            $optionCount = count(
                $optionStorage->loadByProperties(
                    [
                    'question' => $questionId,
                    ]
                )
            );

            // Count votes
            $voteCount = count(
                $voteStorage->loadByProperties(
                    [
                    'question' => $questionId,
                    ]
                )
            );

            // Format question data
            $formatted[] = [
            'id' => $questionId,
            'identifier' => $question->get('identifier')->value,
            'title' => $question->get('title')->value,
            'status' => (bool) $question->get('status')->value,
            'show_results' => (bool) $question->get('show_results')->value,
            'option_count' => $optionCount,
            'vote_count' => $voteCount,
            'created' => $question->get('created')->value,
            'edit_url' => Url::fromRoute(
                'entity.question.edit_form', [
                'question' => $questionId,
                ]
            )->toString(),
            'view_url' => Url::fromRoute(
                'entity.question.canonical', [
                'question' => $questionId,
                ]
            )->toString(),
            ];
        }

        return $formatted;
    }

    /**
     * Builds quick action blocks.
     *
     * @return array
     *   Array of actions with links and icons.
     */
    private function buildQuickActions(): array
    {
        return [
        [
        'title' => $this->t('Create New Question'),
        'url' => Url::fromRoute('entity.question.add_form'),
        'class' => 'action-primary',
        ],
        [
        'title' => $this->t('Create New Option'),
        'url' => Url::fromRoute('entity.option.add_form'),
        'class' => 'action-secondary',
        ],
        [
        'title' => $this->t('Manage All Questions'),
        'url' => Url::fromRoute('entity.question.collection'),
        'class' => 'action-info',
        ],
        [
        'title' => $this->t('Configure voting system'),
        'url' => Url::fromRoute('voting_core.settings'),
        'class' => 'action-settings',
        ],
        [
        'title' => $this->t('Questions page'),
        'url' => Url::fromRoute('voting_core.question_list'),
        'class' => 'action-questions',
        ],
        ];
    }
}
