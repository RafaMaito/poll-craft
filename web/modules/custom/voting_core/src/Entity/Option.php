<?php

declare(strict_types=1);

namespace Drupal\voting_core\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the Option entity.
 *
 * @ContentEntityType(
 *   id = "option",
 *   label = @Translation("Option"),
 *   label_collection = @Translation("Options"),
 *   handlers = {
 *     "list_builder" = "Drupal\voting_core\Entity\Handler\OptionListBuilder",
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
 *   base_table = "voting_option",
 *   admin_permission = "administer_voting_questions",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "title"
 *   },
 *   links = {
 *     "collection" = "/admin/content/options",
 *     "add-form" = "/admin/content/options/add",
 *     "edit-form" = "/admin/content/options/{option}/edit",
 *     "delete-form" = "/admin/content/options/{option}/delete"
 *   }
 * )
 */
final class Option extends ContentEntityBase {

  /**
   * Base field definitions for the Option entity.
   * 
   * @return array<string, \Drupal\Core\Field\BaseFieldDefinition>
   *   An array of base field definitions.
   *
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Reference to the associated Question entity.
    $fields['question'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Question'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'question')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -10,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);

    // Unique identifier for the option within its question.
    $fields['identifier'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Identifier'))
      ->setDescription(new TranslatableMarkup('Unique identifier for this option, within a question.'))
      ->setRequired(TRUE)
      ->setSettings(['max_length' => 128])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('form', TRUE);

    // Title of the option.
    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title'))
      ->setRequired(TRUE)
      ->setSettings(['max_length' => 255])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE);

    // Description of the option.
    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setRequired(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE);

    // Image associated with the option.
    $fields['image'] = BaseFieldDefinition::create('image')
      ->setLabel(new TranslatableMarkup('Image'))
      ->setRequired(FALSE)
      ->setSettings([
        'file_directory' => 'options',
        'file_extensions' => 'png jpg jpeg gif',
        'max_filesize' => '2 MB',
        'alt_field' => FALSE,
        'title_field' => FALSE,
      ])
      ->setDisplayOptions('form', [
        'type' => 'image_image',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE);

    // Weight for ordering options.
    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Weight'))
      ->setDescription(new TranslatableMarkup('Controls the display order of options.'))
      ->setDefaultValue(0)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE);

    // Created timestamp.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    // Changed timestamp.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

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
        return sprintf('Option: %s', $identifier);
      }
    }
    catch (\Throwable $e) {
      // Modern logging instead of watchdog_exception().
      \Drupal::logger('voting_core')->error(
        'Error generating label for Option entity @id: @message',
        [
          '@id' => $this->id(),
          '@message' => $e->getMessage(),
          'exception' => $e,
        ]
      );
    }

    return sprintf('Option #%d', $this->id() ?? 0);
  }
}
