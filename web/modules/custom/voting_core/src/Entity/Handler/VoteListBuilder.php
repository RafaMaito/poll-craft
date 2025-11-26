<?php

declare(strict_types=1);

namespace Drupal\voting_core\Entity\Handler;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a list builder for Vote entities.
 *
 * This list builder displays votes in a table format with key information.
 * 
 * Provides a table view with:
 * - Vote ID
 * - User who voted
 * - Question voted on
 * - Option chosen
 * - Date of the vote
 */
final class VoteListBuilder extends EntityListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    private readonly DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    return new self(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the header for the Vote list table.
   */
  public function buildHeader(): array {
    $header = [
      'id' => $this->t('ID'),
      'user' => $this->t('User'),
      'question' => $this->t('Question'),
      'option' => $this->t('Option chosen'),
      'created' => $this->t('Date'),
    ];

    return $header + parent::buildHeader();
  }

  /**
   * Builds a row for the Vote entity.
   * 
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\voting_core\Entity\Vote $entity */

    $user = $entity->get('user_id')->entity;
    $question = $entity->get('question')->entity;
    $option = $entity->get('option')->entity;

    $created = $entity->get('created')->value;
    $createdFormatted = $created
      ? $this->dateFormatter->format((int) $created, 'short')
      : $this->t('N/A');

    $row = [
      'id' => $entity->id(),
      'user' => $user ? $user->getDisplayName() : $this->t('Anonymous'),
      'question' => $question ? $question->label() : $this->t('N/A'),
      'option' => $option ? $option->label() : $this->t('N/A'),
      'created' => $createdFormatted,
    ];

    return $row + parent::buildRow($entity);
  }

  /**
   * Builds the default operations for a Vote entity.
   */
  protected function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);

    // Remove 'edit' operation - votes should not be edited.
    unset($operations['edit']);

    return $operations;
  }

}
