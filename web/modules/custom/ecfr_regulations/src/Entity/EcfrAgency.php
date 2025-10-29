<?php

namespace Drupal\ecfr_regulations\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Content entity for eCFR Agency.
 *
 * @ContentEntityType(
 *   id = "ecfr_agency",
 *   label = @Translation("eCFR Agency"),
 *   base_table = "ecfr_agency",
 *   handlers = {
 *     "view_builder" = "Drupal\\Core\\Entity\\EntityViewBuilder",
 *     "list_builder" = "Drupal\\Core\\Entity\\EntityListBuilder"
 *   },
 *   admin_permission = "access ecfr regulations analytics",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "name"
 *   },
 *   translatable = FALSE,
 *   fieldable = FALSE
 * )
 */
class EcfrAgency extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entityType): array {
    $fields = parent::baseFieldDefinitions($entityType);

    // Primary ID field.
    if (!isset($fields['id'])) {
      $fields['id'] = BaseFieldDefinition::create('integer')
        ->setLabel(t('ID'))
        ->setDescription(t('Primary ID'))
        ->setReadOnly(TRUE);
    }
    // UUID field.
    if (!isset($fields['uuid'])) {
      $fields['uuid'] = BaseFieldDefinition::create('uuid')
        ->setLabel(t('UUID'))
        ->setDescription(t('UUID'))
        ->setReadOnly(TRUE);
    }

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setRequired(TRUE)
      ->setSettings(['max_length' => 255])
      ->addConstraint('NotBlank');

    $fields['slug'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Slug'))
      ->setRequired(TRUE)
      ->setSettings(['max_length' => 128])
      ->addConstraint('NotBlank')
      ->addConstraint('UniqueField');

    $fields['short_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Short name'))
      ->setSettings(['max_length' => 128]);

    $fields['cfr_references'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('CFR References JSON'))
      ->setDescription(t('Raw JSON encoded CFR references for this agency.'));

    $fields['titles'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Titles covered JSON'))
      ->setDescription(t('JSON encoded array of CFR title numbers for this agency.'));

    $fields['word_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Latest word count'))
      ->setDefaultValue(0);

    $fields['parent'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Parent Agency'))
      ->setSetting('target_type', 'ecfr_agency')
      ->setCardinality(1);

    $fields['changed'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Changed'))
      ->setRequired(FALSE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    return $fields;
  }

}
