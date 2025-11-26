<?php

declare(strict_types=1);

namespace Drupal\voting_core\Entity\Handler;

use Drupal\Core\Url;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * List builder for Question entities.
 *
 * Provides a table view with:
 * - Question title and identifier
 * - Status (Active/Inactive)
 * - Option count
 * - Vote count
 * - Operations (Edit, Delete, Add Options)
 */
final class QuestionListBuilder extends EntityListBuilder {

  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    /** @var static $instance */
    $instance = parent::createInstance($container, $entity_type);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, string>
   *   Associative array of header labels.
   */
  public function buildHeader(): array {
    $header = [
      'title' => $this->t('Title'),
      'identifier' => $this->t('Identifier'),
      'status' => $this->t('Status'),
      'show_results' => $this->t('Show Results'),
      'options' => $this->t('Options'),
      'votes' => $this->t('Votes'),
    ];

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   Renderable row array.
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\voting_core\Entity\Question $entity */

    // Count options.
    $optionCount = $this->countOptions((int) $entity->id());

    // Count votes.
    $voteCount = $this->countVotes((int) $entity->id());

    $row = [
      'title' => [
        'data' => [
          '#type' => 'link',
          '#title' => $entity->label(),
          '#url' => $entity->toUrl(),
        ],
      ],
      'identifier' => $entity->get('identifier')->value,
      'status' => [
        'data' => [
          '#markup' => $entity->get('status')->value
            ? '<span style="color: green;">✓ ' . $this->t('Active') . '</span>'
            : '<span style="color: red;">✗ ' . $this->t('Inactive') . '</span>',
        ],
      ],
      'show_results' => $entity->get('show_results')->value
        ? $this->t('Yes')
        : $this->t('No'),
      'options' => [
        'data' => [
          '#markup' => $optionCount > 0
            ? $optionCount . ' ' . ($optionCount === 1 ? $this->t('option') : $this->t('options'))
            : '<em>' . $this->t('No options') . '</em>',
        ],
      ],
      'votes' => [
        'data' => [
          '#markup' => '<strong>' . $voteCount . '</strong>',
        ],
      ],
    ];

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity): array {
    /** @var \Drupal\voting_core\Entity\Question $entity */
    $operations = parent::getDefaultOperations($entity);

    // Add "Add Options" operation.
    $operations['add_options'] = [
      'title' => $this->t('Add Options'),
      'weight' => 15,
      'url' => Url::fromRoute(
        'entity.option.add_form',
        [],
        ['query' => ['question' => $entity->id()]],
      ),
    ];

    // Conditionally add "View Results" operation if votes exist.
    if ($this->countVotes((int) $entity->id()) > 0) {
      $operations['view_results'] = [
        'title' => $this->t('View Results'),
        'weight' => 20,
        'url' => Url::fromRoute(
          'voting_core.view_results',
          ['identifier' => $entity->get('identifier')->value],
        ),
      ];
    }

    return $operations;
  }
  /**
   * Count options for a question.
   */
  protected function countOptions(int $questionId): int {
    return (int) $this->entityTypeManager
      ->getStorage('option')
      ->getQuery()
      ->condition('question', $questionId)
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Count votes for a question.
   */
  protected function countVotes(int $questionId): int {
    return (int) $this->entityTypeManager
      ->getStorage('vote')
      ->getQuery()
      ->condition('question', $questionId)
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }
}
