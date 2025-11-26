<?php

declare(strict_types=1);

namespace Drupal\voting_core\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Entity\EntityChangedTrait;

/**
 * Defines the Vote entity.
 *
 * @ContentEntityType(
 *   id = "vote",
 *   label = @Translation("Vote"),
 *   label_collection = @Translation("Votes"),
 *   label_singular = @Translation("vote"),
 *   label_plural = @Translation("votes"),
 *   label_count = @PluralTranslation(
 *     singular = "@count vote",
 *     plural = "@count votes"
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\voting_core\Entity\Handler\VoteListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "voting_vote",
 *   admin_permission = "administer Votes",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid"
 *   },
 *   links = {
 *     "collection" = "/admin/content/votes",
 *     "canonical" = "/admin/content/votes/{vote}",
 *     "delete-form" = "/admin/content/votes/{vote}/delete"
 *   }
 * )
 */
final class Vote extends ContentEntityBase {
  use EntityChangedTrait;

  /**
   * Base field definitions for the Vote entity.
   *
   * @return array<string, \Drupal\Core\Field\BaseFieldDefinition>
   *  An array of base field definitions.
   *  
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Reference to the associated Question entity.
    $fields['question'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Question'))
      ->setDescription(new TranslatableMarkup('The Question this vote is for.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'question')
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Reference to the associated Option entity.
    $fields['option'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Option'))
      ->setDescription(new TranslatableMarkup('The option that was voted for.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'option')
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Reference to the User who cast the vote.
    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('User'))
      ->setDescription(new TranslatableMarkup('The user who cast this vote.'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'user')
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Timestamp when the vote was created.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time that the vote was cast.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   *
   * CRITICAL: This method prevents NULL label errors in Views and logs.
   */
  public function label(): string {
    try {
      $user = $this->get('user_id')->entity;
      $question = $this->get('question')->entity;
      $option = $this->get('option')->entity;

      if ($user && $question && $option) {
        return sprintf(
          'Vote #%d by %s on "%s" (chose "%s")',
          $this->id(),
          $user->getDisplayName(),
          $question->label(),
          $option->label()
        );
      }

      // Fallback if some entities are missing.
      if ($question) {
        return sprintf('Vote #%d on "%s"', $this->id(), $question->label());
      }
    } catch (\Throwable $e) {
      // Entities do not have DI.
      \Drupal::logger('voting_core')->error(
        'Error generating label for vote @id: @msg',
        [
          '@id' => $this->id(),
          '@msg' => $e->getMessage(),
          'exception' => $e,
        ]
      );
    }

    // Ultimate fallback - always returns a string, never NULL.
    return sprintf('Vote #%d', $this->id() ?? 0);
  }
}
