<?php

namespace Drupal\ecfr_regulations\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use TextAnalysis\Tokenizers\WhitespaceTokenizer;

/**
 * Content entity for eCFR Regulation text reference.
 *
 * @ContentEntityType(
 *   id = "ecfr_regulation",
 *   label = @Translation("eCFR Regulation"),
 *   base_table = "ecfr_regulation",
 *   handlers = {
 *     "view_builder" = "Drupal\\Core\\Entity\\EntityViewBuilder",
 *     "list_builder" = "Drupal\\Core\\Entity\\EntityListBuilder"
 *   },
 *   admin_permission = "access ecfr regulations analytics",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "revision" = "revision_id"
 *   },
 *   translatable = FALSE,
 *   fieldable = FALSE
 * )
 */
class EcfrRegulation extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entityType): array {
    $fields = parent::baseFieldDefinitions($entityType);

    if (!isset($fields['id'])) {
      $fields['id'] = BaseFieldDefinition::create('integer')
        ->setLabel(t('ID'))
        ->setDescription(t('Primary ID'))
        ->setReadOnly(TRUE);
    }
    if (!isset($fields['uuid'])) {
      $fields['uuid'] = BaseFieldDefinition::create('uuid')
        ->setLabel(t('UUID'))
        ->setDescription(t('UUID'))
        ->setReadOnly(TRUE);
    }

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setSettings(['max_length' => 16])
      ->setRequired(TRUE)
      ->setRevisionable(TRUE);

    $fields['chapter'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Chapter'))
      ->setSettings(['max_length' => 32])
      ->setRevisionable(TRUE);

    $fields['subtitle'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Subtitle'))
      ->setSettings(['max_length' => 32])
      ->setRevisionable(TRUE);

    $fields['agency_slug'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Agency slug'))
      ->setSettings(['max_length' => 128])
      ->setRequired(TRUE)
      ->setRevisionable(TRUE);

    $fields['raw_text'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Raw text'))
      ->setDescription(t('Aggregated plain text content for the regulation chapter.'))
      ->setRevisionable(TRUE);

    $fields['word_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Word count'))
      ->setDefaultValue(0)
      ->setRevisionable(TRUE);

    $fields['checksum'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Checksum'))
      ->setSettings(['max_length' => 64])
      ->setDefaultValue('')
      ->setRevisionable(TRUE);

    $fields['changed'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Changed'))
      ->setRevisionable(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    // Add revision fields.
    $fields['revision_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Revision ID'))
      ->setDescription(t('The revision ID.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['revision_log'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Revision log'))
      ->setDescription(t('The log entry explaining the changes in this revision.'));

    $fields['revision_created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Revision created'))
      ->setDescription(t('The time that the current revision was created.'));

    $fields['revision_user'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Revision user'))
      ->setDescription(t('The user ID of the author of the current revision.'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getCurrentUserId');

    return $fields;
  }

  /**
   * Performs text analysis on the raw_text field.
   *
   * @return array
   *   An array with 'frequencies' and 'lexical_diversity'.
   */
  public function getTextAnalysis(): array {
    $rawText = (string) $this->get('raw_text')->value;
    if ($rawText === '') {
      return [
        'frequencies' => [],
        'lexical_diversity' => [
          'ttr' => 0,
          'unique_words' => 0,
          'total_words' => 0,
        ],
      ];
    }

    $tokenizer = new WhitespaceTokenizer();
    $rawTokens = $tokenizer->tokenize($rawText);

    // Clean tokens: lowercase and remove non-letter characters (including
    // numbers).
    $normalizedTokens = array_map(static function ($token) {
      return preg_replace('/[^a-z]/', '', strtolower($token));
    }, $rawTokens);
    $normalizedTokens = array_filter($normalizedTokens, static function ($token) {
      return !empty($token) && strlen($token) > 2;
    });
    // Reindex array.
    $normalizedTokens = array_values($normalizedTokens);

    // Remove stop words.
    $stopWords = [
      // Articles.
      'the', 'a', 'an',
      // Prepositions.
      'of', 'to', 'in', 'on', 'at', 'by', 'for', 'with', 'from', 'into', 'through', 'during', 'before', 'after', 'above', 'below', 'between', 'among', 'under', 'over', 'behind', 'beside', 'near', 'against', 'along', 'across', 'around', 'throughout', 'within', 'without', 'toward', 'towards', 'upon', 'about', 'regarding', 'concerning', 'amongst', 'amid', 'amidst', 'onto', 'off', 'out', 'up', 'down', 'away', 'back', 'forth', 'here', 'there', 'where', 'when', 'why', 'how', 'what', 'which', 'who', 'whom', 'whose',
      // Conjunctions.
      'and', 'or', 'but', 'nor', 'so', 'for', 'yet', 'although', 'though', 'while', 'whereas', 'unless', 'if', 'then', 'else', 'whether', 'either', 'neither', 'not', 'only', 'both', 'all', 'some', 'any', 'every', 'each', 'no', 'none', 'nothing', 'nobody', 'nowhere', 'never', 'always', 'often', 'sometimes', 'usually', 'seldom', 'rarely', 'other', 'more', 'most', 'less', 'least', 'such',
      // Pronouns.
      'i', 'me', 'my', 'myself', 'we', 'us', 'our', 'ourselves', 'you', 'your', 'yours', 'yourself', 'yourselves', 'he', 'him', 'his', 'himself', 'she', 'her', 'hers', 'herself', 'it', 'its', 'itself', 'they', 'them', 'their', 'theirs', 'themselves', 'this', 'that', 'these', 'those', 'who', 'whom', 'whose', 'which', 'what',
      // Auxiliary verbs and be verbs.
      'be', 'is', 'am', 'are', 'was', 'were', 'been', 'being', 'have', 'has', 'had', 'having', 'do', 'does', 'did', 'doing', 'will', 'would', 'shall', 'should', 'may', 'might', 'must', 'can', 'could', 'will', 'would', 'shall', 'should', 'may', 'might', 'must', 'can', 'could',
      // Other common words.
      'as', 'like', 'such', 'than', 'so', 'very', 'too', 'also', 'even', 'just', 'still', 'again', 'once', 'now', 'then', 'soon', 'later', 'ago', 'before', 'after', 'since', 'until', 'while', 'during', 'through', 'across', 'against', 'along', 'among', 'around', 'behind', 'below', 'beneath', 'beside', 'between', 'beyond', 'inside', 'outside', 'under', 'underneath', 'upon', 'within', 'without',
      'act', 'section', 'part', 'date', 'usc',
    ];
    $contentTokens = array_filter($normalizedTokens, static function ($token) use ($stopWords) {
      return !in_array($token, $stopWords);
    });
    // Reindex again after filtering stop words.
    $contentTokens = array_values($contentTokens);

    $totalWordCount = (int) $this->get('word_count')->value;
    $uniqueWordCount = count(array_unique($contentTokens));
    $typeTokenRatio = $totalWordCount > 0 ? $uniqueWordCount / $totalWordCount : 0;

    $termFrequency = array_count_values($contentTokens);
    arsort($termFrequency);

    return [
      'frequencies' => $termFrequency,
      'lexical_diversity' => [
        'ttr' => round($typeTokenRatio, 4),
        'unique_words' => $uniqueWordCount,
        'total_words' => $totalWordCount,
      ],
    ];
  }

  /**
   * Default value callback for 'revision_user' base field definition.
   *
   * @see ::baseFieldDefinitions()
   *
   * @return array
   *   An array of default values.
   */
  public static function getCurrentUserId() {
    return [\Drupal::currentUser()->id()];
  }

}
