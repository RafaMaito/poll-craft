<?php

declare(strict_types=1);

namespace Drupal\voting_core\Entity\Handler;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * View builder for Question entities.
 *
 * This customizes how questions are displayed, particularly
 * showing their related options inline.
 * 
 * Provides a detailed view with:
 * - Question title and description
 * - List of associated options with identifiers, 
 *   titles, descriptions, and weights
 * - Link to add new options
 */
final class QuestionViewBuilder extends EntityViewBuilder {

  /**
   * Entity type manager service.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    /** @var self $instance */
    $instance = parent::createInstance($container, $entity_type);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * Customizes the full view mode to include options.
   * 
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being viewed.
   * @param string $view_mode
   *   The view mode.
   * @param string|null $langcode
   *   The language code.
   */
  public function view(EntityInterface $entity, $view_mode = 'full', $langcode = NULL): array {
    $build = parent::view($entity, $view_mode, $langcode);

    if ($view_mode === 'full') {
      $build['options'] = $this->buildOptionsSection($entity);
    }

    return $build;
  }

  /**
   * Builds the "Options" section for a question.
   *
   * @param \Drupal\Core\Entity\EntityInterface $question
   *   The Question entity.
   * @return array
   *   Renderable array for the options section.
   */
  protected function buildOptionsSection(EntityInterface $question): array {
    /** @var \Drupal\voting_core\Entity\Question $question */
    $option_storage = $this->entityTypeManager->getStorage('option');
    $options = $option_storage->loadByProperties([
      'question' => $question->id(),
    ]);

    if (empty($options)) {
      return [
        '#type' => 'details',
        '#title' => $this->t('Options'),
        '#open' => TRUE,
        '#weight' => 10,
        'empty' => [
          '#markup' => $this->t('No options defined yet.'),
        ],
        'add_link' => [
          '#type' => 'link',
          '#title' => $this->t('+ Add option'),
          '#url' => Url::fromRoute('entity.option.add_form', [], [
            'query' => ['question' => $question->id()],
          ]),
          '#attributes' => [
            'class' => ['button', 'button--primary', 'button--small'],
          ],
        ],
      ];
    }

    $rows = [];
    foreach ($options as $option) {
      /** @var \Drupal\voting_core\Entity\Option $option */
      $rows[] = [
        $option->get('identifier')->value,
        $option->get('title')->value,
        $option->get('description')->value ?: '-',
        $option->get('weight')->value,
        Link::fromTextAndUrl(
          $this->t('Edit'),
          Url::fromRoute('entity.option.edit_form', ['option' => $option->id()])
        )->toRenderable(),
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Options (@count)', ['@count' => count($options)]),
      '#open' => TRUE,
      '#weight' => 10,
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Identifier'),
          $this->t('Title'),
          $this->t('Description'),
          $this->t('Weight'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No options available.'),
      ],
      'add_link' => [
        '#type' => 'link',
        '#title' => $this->t('+ Add option'),
        '#url' => Url::fromRoute('entity.option.add_form', [], [
          'query' => ['question' => $question->id()],
        ]),
        '#attributes' => [
          'class' => ['button', 'button--primary', 'button--small'],
        ],
      ],
    ];
  }

}
