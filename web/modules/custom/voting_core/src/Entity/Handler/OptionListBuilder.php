<?php

declare(strict_types=1);

namespace Drupal\voting_core\Entity\Handler;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * List builder for Option entities.
 * 
 * Provides a table view with:
 * - Option title and identifier
 * - Associated Question
 * - Weight
 */
class OptionListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   *
   * @return array<string, string>
   *   Associative array of header labels.
   */
  public function buildHeader() {
    $header = [
      'title' => $this->t('Title'),
      'identifier' => $this->t('Identifier'),
      'question' => $this->t('Question'),
      'weight' => $this->t('Weight'),
    ];
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   Renderable row array.
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\voting_core\Entity\Option $entity */
    $question = $entity->get('question')->entity;
    $questionTitle = $question ? $question->label() : $this->t('(Unknown)');

    $row = [
      'title' => $entity->label(),
      'identifier' => $entity->get('identifier')->value,
      'question' => $questionTitle,
      'weight' => $entity->get('weight')->value,
    ];

    return $row + parent::buildRow($entity);
  }
}
