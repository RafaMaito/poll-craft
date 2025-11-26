<?php

declare(strict_types=1);

namespace Drupal\voting_core\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the Question entity.
 *
 * @ContentEntityType(
 *   id = "question",
 *   label = @Translation("Question"),
 *   label_collection = @Translation("Questions"),
 *   handlers = {
 *     "list_builder" = "Drupal\voting_core\Entity\Handler\QuestionListBuilder",
 *     "view_builder" = "Drupal\voting_core\Entity\Handler\QuestionViewBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "add" = "Drupal\Core\Entity\ContentEntityForm",
 *       "edit" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "voting_question",
 *   admin_permission = "administer_voting_questions",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "title"
 *   },
 *   links = {
 *     "collection" = "/admin/content/questions",
 *     "add-form" = "/admin/content/questions/add",
 *     "edit-form" = "/admin/content/questions/{question}/edit",
 *     "delete-form" = "/admin/content/questions/{question}/delete",
 *     "canonical" = "/admin/content/questions/{question}"
 *   }
 * )
 */
final class Question extends ContentEntityBase {

  /**
   * Base field definitions for the Question entity.
   * 
   * @return array<string, \Drupal\Core\Field\BaseFieldDefinition>
   *   An array of base field definitions.
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Unique identifier for external applications.
    $fields['identifier'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Identifier'))
      ->setDescription(new TranslatableMarkup('Unique identifier used by external applications.'))
      ->setRequired(TRUE)
      ->setSettings(['max_length' => 128])
      ->addConstraint('UniqueField', [])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -4,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Title of the question.
    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title'))
      ->setRequired(TRUE)
      ->setSettings(['max_length' => 255])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Description of the question.
    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setRequired(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Show results after vote.
    $fields['show_results'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Show results after vote'))
      ->setDescription(new TranslatableMarkup('If disabled, results will not be exposed through the API or UI.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => TRUE,
        ],
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE);

    // Indicates whether this question is active for voting_core.
    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Active'))
      ->setDescription(new TranslatableMarkup('Indicates whether this question is active for voting_core.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => TRUE,
        ],
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE);

    // Created timestamp.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    // Changed timestamp.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    // Voting end date.
    $fields['voting_end_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Voting end date'))
      ->setDescription(new TranslatableMarkup(
        'Date when voting will be closed automatically.'
      ))
      ->setRequired(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => 15,
      ]);
    return $fields;
  }

  /**
   * {@inheritdoc}
   *
   * CRITICAL: This method prevents NULL label errors in Views and logs.
   */
  public function label(): string {
    try {
      $title = $this->get('title')->value;
      if ($title) {
        return $title;
      }

      $identifier = $this->get('identifier')->value;
      if ($identifier) {
        return sprintf('Question: %s', $identifier);
      }
    } catch (\Throwable $e) {
      // Correct logger for entity.
      \Drupal::logger('voting_core')->error(
        'Error generating label for Question entity @id: @message',
        [
          '@id' => $this->id(),
          '@message' => $e->getMessage(),
          'exception' => $e,
        ]
      );
    }

    return sprintf('Question #%d', $this->id() ?? 0);
  }
}
