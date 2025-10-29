<?php

namespace Drupal\ecfr_regulations;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Agency Regulation.
 */
class EcfrRegulation {
  use StringTranslationTrait;

  /**
   * Cache backend for regulation operations.
   */
  protected CacheBackendInterface $cache;

  /**
   * Configuration factory service.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Entity type manager service.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * File system service for accessing stored XML.
   */
  protected FileSystemInterface $fileSystem;

  /**
   * Logger channel for import and extraction messages.
   */
  protected LoggerInterface $logger;

  /**
   * Date formatter service.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Agency service for related operations.
   */
  protected EcfrAgency $agencyService;

  public function __construct(
    CacheBackendInterface $cache,
    ConfigFactoryInterface $configFactory,
    EntityTypeManagerInterface $entityTypeManager,
    FileSystemInterface $fileSystem,
    LoggerInterface $logger,
    DateFormatterInterface $dateFormatter,
    TranslationInterface $translation,
    EcfrAgency $agencyService,
  ) {
    $this->cache = $cache;
    $this->configFactory = $configFactory;
    $this->entityTypeManager = $entityTypeManager;
    $this->fileSystem = $fileSystem;
    $this->logger = $logger;
    $this->dateFormatter = $dateFormatter;
    $this->stringTranslation = $translation;
    $this->agencyService = $agencyService;
  }

  /**
   * Import agencies and regulations into entities.
   */
  public function import(array $options = []): void {
    $agencies = $this->agencyService->getAgencies();
    if (!empty($options['limit'])) {
      $agencies = array_slice($agencies, 0, (int) $options['limit']);
    }

    $processedTitleMap = [];
    $regulationCount = 0;

    foreach ($agencies as $agency) {
      $this->processAgencyReferences($agency, $regulationCount, $processedTitleMap);
    }

    // Update checksums for processed titles.
    foreach (array_keys($processedTitleMap) as $titleNumber) {
      $this->updateTitleXmlChecksum($titleNumber);
    }

    $this->logger->info('Imported @agency_count agencies. Regulations: @reg_count.', [
      '@agency_count' => count($agencies),
      '@reg_count' => $regulationCount,
    ]);
  }

  /**
   * Batch process agencies for import.
   */
  public static function batchImportAgencies(array $agencyBatch, array &$context): void {
    $regulationService = \Drupal::service('ecfr_regulations.regulation_service');

    if (!isset($context['results']['processed_titles'])) {
      $context['results']['processed_titles'] = [];
    }
    if (!isset($context['results']['count_regs'])) {
      $context['results']['count_regs'] = 0;
    }

    $processedTitleMap = &$context['results']['processed_titles'];
    $regulationCount = &$context['results']['count_regs'];

    foreach ($agencyBatch as $agency) {
      $regulationService->processAgencyReferences($agency, $regulationCount, $processedTitleMap);
      $context['results']['agency_count'] = ($context['results']['agency_count'] ?? 0) + 1;
    }

    $context['message'] = t('Processed @count agencies, @regs regulations imported.', [
      '@count' => $context['results']['agency_count'],
      '@regs' => $regulationCount,
    ]);
  }

  /**
   * Batch finished callback.
   */
  public static function batchImportFinished($success, $results, $operations): void {
    $regulationService = \Drupal::service('ecfr_regulations.regulation_service');

    if ($success) {
      // Update checksums for processed titles.
      foreach (array_keys($results['processed_titles']) as $title) {
        $regulationService->updateTitleXmlChecksum($title);
      }

      \Drupal::logger('ecfr_regulations')->info('Batch import completed. Agencies: @agency_count, Regulations: @reg_count.', [
        '@agency_count' => $results['agency_count'] ?? 0,
        '@reg_count' => $results['count_regs'] ?? 0,
      ]);

      \Drupal::messenger()->addStatus(t('Import completed successfully. Processed @agencies agencies and @regs regulations.', [
        '@agencies' => $results['agency_count'] ?? 0,
        '@regs' => $results['count_regs'] ?? 0,
      ]));
    }
    else {
      \Drupal::logger('ecfr_regulations')->error('Batch import failed.');
      \Drupal::messenger()->addError(t('Import failed. Check the logs for details.'));
    }
  }

  /**
   * Process CFR references for an agency and its children recursively.
   */
  public function processAgencyReferences(array $agency, int &$regulationCount, array &$processedTitleMap): void {
    foreach ($agency['cfr_references'] as $reference) {
      if (!empty($reference['title']) && (!empty($reference['chapter']) || !empty($reference['subtitle']))) {
        $titleNumber = (string) $reference['title'];
        if (!isset($processedTitleMap[$titleNumber])) {
          if (!$this->hasTitleXmlChanged($titleNumber)) {
            $this->logger->notice('Title @title XML unchanged, skipping import.', ['@title' => $titleNumber]);
            continue;
          }
          $processedTitleMap[$titleNumber] = TRUE;
        }
        $regulationText = '';
        $sectionIdentifier = '';
        $referenceType = '';
        if (!empty($reference['chapter'])) {
          $regulationText = $this->extractChapterFromLocalXml($titleNumber, (string) $reference['chapter'], $agency['slug']);
          $sectionIdentifier = (string) $reference['chapter'];
          $referenceType = 'chapter';
        }
        elseif (!empty($reference['subtitle'])) {
          $this->logger->notice('Processing subtitle @subtitle for title @title', [
            '@subtitle' => $reference['subtitle'],
            '@title' => $reference['title'],
          ]);
          $regulationText = $this->extractSubtitleFromLocalXml($titleNumber, (string) $reference['subtitle'], $agency['slug']);
          $sectionIdentifier = (string) $reference['subtitle'];
          $referenceType = 'subtitle';
        }
        if ($regulationText !== '') {
          $regulationCount++;
          $this->logger->notice('Imported regulation: Title @title, @type @identifier (Agency: @agency)', [
            '@title' => $reference['title'],
            '@type' => $referenceType,
            '@identifier' => $sectionIdentifier,
            '@agency' => $agency['slug'],
          ]);
        }
      }
    }

    if (!empty($agency['children'])) {
      foreach ($agency['children'] as $child) {
        $this->processAgencyReferences($child, $regulationCount, $processedTitleMap);
      }
    }
  }

  /**
   * Get raw regulation text for analytics display.
   */
  public function getRegulationRaw(string $agencySlug, int $regulationId): ?array {
    $regulationStorage = $this->entityTypeManager->getStorage('ecfr_regulation');
    $regulation = $regulationStorage->load($regulationId);
    if (!$regulation || $regulation->get('agency_slug')->value !== $agencySlug) {
      return NULL;
    }
    $identifier = $regulation->get('chapter')->value ?: $regulation->get('subtitle')->value;
    $type = $regulation->get('chapter')->value ? 'chapter' : 'subtitle';
    return [
      'title' => $regulation->get('title')->value,
      $type => $identifier,
      'text' => $regulation->get('raw_text')->value,
    ];
  }

  /**
   * Build formatted table rows for agency regulations.
   *
   * @param iterable $regulations
   *   Regulation entities belonging to an agency.
   * @param string $agencySlug
   *   Agency slug for operations links.
   */
  public function buildRegulationTableRows(iterable $regulations, string $agencySlug): array {
    $rows = [];
    foreach ($regulations as $regulation) {
      $type = $regulation->get('chapter')->value ?: $regulation->get('subtitle')->value;

      $rows[] = [
        $regulation->get('title')->value,
        [
          'data' => [
            '#type' => 'link',
            '#title' => $type,
            '#url' => Url::fromRoute('ecfr_regulations.analytics_regulation_text', [
              'agencySlug' => $agencySlug,
              'regulationId' => $regulation->id(),
            ]),
          ],
        ],
        $regulation->get('word_count')->value,
        $this->dateFormatter->format($regulation->get('changed')->value, 'ecfr_date_format'),
        $regulation->get('checksum')->value,
      ];
    }
    return $rows;
  }

  /**
   * Extracts chapter text from stored title XML.
   *
   * Returns the extracted text, or an empty string if extraction failed.
   */
  public function extractChapterFromLocalXml(string $title, string $chapter, ?string $agencySlug, ?string $date = NULL): string {
    $chapterNormalized = EcfrUtilities::normalizeChapterIdentifier($chapter);
    $cid = 'ecfr_regulations:local_chapter_text:' . $title . ':' . $chapterNormalized . ':' . ($date ?? 'latest');

    if ($cache = $this->cache->get($cid)) {
      $cachedData = $cache->data;
      $text = is_string($cachedData['text']) ? $cachedData['text'] : '';
      $actualDate = $cachedData['date'];
      if ($text !== '' && $agencySlug) {
        $this->persistRegulationEntity([
          'title' => $title,
          'chapter' => $chapterNormalized,
          'raw_text' => $text,
          'latest_issue_date' => $actualDate,
        ], $agencySlug);
      }
      return $text;
    }

    $result = $this->getLocalTitleXml($title, $date);
    $xmlString = $result['xml'];
    $actualDate = $result['date'];
    if ($xmlString === NULL) {
      $this->logger->warning('No local XML found for title @title, cannot extract chapter @chapter.', [
        '@title' => $title,
        '@chapter' => $chapter,
      ]);
      return '';
    }

    $sx = @simplexml_load_string($xmlString);
    if (!$sx) {
      $this->logger->warning('Failed to parse local XML for title @title.', ['@title' => $title]);
      return '';
    }

    $possibleMatches = [$chapterNormalized];
    if ($chapter !== $chapterNormalized) {
      $possibleMatches[] = $chapter;
    }
    if (preg_match('/^\d+$/', $chapter)) {
      $romanVersion = EcfrUtilities::intToRoman((int) $chapter);
      if ($romanVersion !== $chapter) {
        $possibleMatches[] = $romanVersion;
      }
    }
    elseif (preg_match('/^[IVXLCDM]+$/i', $chapter)) {
      $intVersion = EcfrUtilities::romanToInt(strtoupper($chapter));
      if ($intVersion !== 0) {
        $possibleMatches[] = (string) $intVersion;
      }
    }

    $target = EcfrUtilities::findChapterNode($sx, array_unique($possibleMatches));
    if ($target === NULL) {
      $this->logger->warning('Chapter @chapter not found in local XML for title @title.', [
        '@chapter' => $chapterNormalized,
        '@title' => $title,
      ]);
      return '';
    }

    $lines = EcfrUtilities::extractChapterPartTexts($target, [$this, 'extractPartStructured']);
    $text = trim(implode("\n", $lines));

    $ttl = (int) $this->configFactory->get('ecfr_regulations.settings')->get('cache_lifetime') ?: 86400;
    $this->cache->set($cid, ['text' => $text, 'date' => $actualDate], time() + $ttl, ['ecfr_regulations']);

    if ($text !== '' && $agencySlug) {
      $this->persistRegulationEntity([
        'title' => $title,
        'chapter' => $chapterNormalized,
        'raw_text' => $text,
        'latest_issue_date' => $actualDate,
      ], $agencySlug);
    }

    return $text;
  }

  /**
   * Extract raw subtitle text from a locally stored Title XML file.
   */
  public function extractSubtitleFromLocalXml(string $title, string $subtitle, ?string $agencySlug, ?string $date = NULL): string {
    $subtitleNormalized = EcfrUtilities::normalizeChapterIdentifier($subtitle);
    $cid = 'ecfr_regulations:local_subtitle_text:' . $title . ':' . $subtitleNormalized . ':' . ($date ?? 'latest');

    if ($cache = $this->cache->get($cid)) {
      $cachedData = $cache->data;
      $text = is_string($cachedData['text']) ? $cachedData['text'] : '';
      $actualDate = $cachedData['date'];
      if ($text !== '' && $agencySlug) {
        $this->persistRegulationEntity([
          'title' => $title,
          'subtitle' => $subtitleNormalized,
          'raw_text' => $text,
          'latest_issue_date' => $actualDate,
        ], $agencySlug);
      }
      return $text;
    }

    $result = $this->getLocalTitleXml($title, $date);
    $xmlString = $result['xml'];
    $actualDate = $result['date'];
    if ($xmlString === NULL) {
      $this->logger->warning('No local XML found for title @title, cannot extract subtitle @subtitle.', [
        '@title' => $title,
        '@subtitle' => $subtitle,
      ]);
      return '';
    }

    $sx = @simplexml_load_string($xmlString);
    if (!$sx) {
      $this->logger->warning('Failed to parse local XML for title @title.', ['@title' => $title]);
      return '';
    }

    $possibleMatches = [$subtitleNormalized];
    if ($subtitle !== $subtitleNormalized) {
      $possibleMatches[] = $subtitle;
    }
    if (preg_match('/^\d+$/', $subtitle)) {
      $romanVersion = EcfrUtilities::intToRoman((int) $subtitle);
      if ($romanVersion !== '') {
        $possibleMatches[] = $romanVersion;
      }
    }
    elseif (preg_match('/^[IVXLCDM]+$/i', $subtitle)) {
      $intVersion = EcfrUtilities::romanToInt(strtoupper($subtitle));
      if ($intVersion !== 0) {
        $possibleMatches[] = (string) $intVersion;
      }
    }

    $target = EcfrUtilities::findSubtitleNode($sx, array_unique($possibleMatches));
    if ($target === NULL) {
      $this->logger->warning('Subtitle @subtitle not found in local XML for title @title.', [
        '@subtitle' => $subtitleNormalized,
        '@title' => $title,
      ]);
      return '';
    }

    $lines = EcfrUtilities::extractSubtitlePartTexts($target, [$this, 'extractPartStructured']);
    $text = trim(implode("\n", $lines));

    $ttl = (int) $this->configFactory->get('ecfr_regulations.settings')->get('cache_lifetime') ?: 86400;
    $this->cache->set($cid, ['text' => $text, 'date' => $actualDate], time() + $ttl, ['ecfr_regulations']);

    if ($text !== '' && $agencySlug) {
      $this->persistRegulationEntity([
        'title' => $title,
        'subtitle' => $subtitleNormalized,
        'raw_text' => $text,
        'latest_issue_date' => $actualDate,
      ], $agencySlug);
    }

    return $text;
  }

  /**
   * Loads a locally stored title XML file.
   *
   * If $date is NULL, picks the latest snapshot present.
   * Returns an array with 'xml' and 'date' keys.
   */
  public function getLocalTitleXml(string $title, ?string $date = NULL): array {
    $settings = $this->configFactory->get('ecfr_regulations.settings');
    $configured = trim((string) ($settings->get('xml_storage_directory') ?? 'public://ecfr_titles'));
    if ($configured === '') {
      $configured = 'public://ecfr_titles';
    }

    $pattern = '/^title-' . preg_quote((string) $title, '/') . '-(\d{4}-\d{2}-\d{2})\.xml$/';

    if (!$this->fileSystem->realpath($configured)) {
      return ['xml' => NULL, 'date' => NULL];
    }

    $candidates = EcfrUtilities::scanTitleSnapshots($this->fileSystem, $configured, $pattern);

    if ($date) {
      if (!isset($candidates[$date])) {
        return ['xml' => NULL, 'date' => NULL];
      }
      return ['xml' => file_get_contents($candidates[$date]) ?: NULL, 'date' => $date];
    }

    if (!$candidates) {
      return ['xml' => NULL, 'date' => NULL];
    }

    krsort($candidates);
    $latestDate = key($candidates);
    $latestUri = reset($candidates);
    return ['xml' => file_get_contents($latestUri) ?: NULL, 'date' => $latestDate];
  }

  /**
   * Structured extraction of a single PART's text from full title XML.
   */
  public function extractPartStructured(string $xmlString, string $part): string {
    if ($xmlString === '' || $part === '') {
      return '';
    }
    $sx = @simplexml_load_string($xmlString);
    if (!$sx) {
      return '';
    }
    $partNodes = $sx->xpath("//DIV5[@TYPE='PART']");
    if (!is_array($partNodes) || !$partNodes) {
      return '';
    }

    $target = NULL;
    foreach ($partNodes as $node) {
      $attrN = (string) ($node['N'] ?? '');
      $head = (string) ($node->HEAD ?? '');
      if ($attrN === (string) $part || preg_match('/^\s*PART\s+' . preg_quote($part, '/') . '\b/i', $head)) {
        $target = $node;
        break;
      }
    }

    if ($target === NULL) {
      return '';
    }

    $lines = [];
    $partHead = trim(preg_replace('/\s+/u', ' ', (string) ($target->HEAD ?? '')));
    if ($partHead !== '') {
      $lines[] = $partHead;
    }

    foreach (['AUTH', 'SOURCE'] as $mini) {
      foreach ($target->xpath('./' . $mini) as $miniNode) {
        $miniTextParts = [];
        $hed = trim((string) ($miniNode->HED ?? ''));
        if ($hed !== '') {
          $miniTextParts[] = $hed;
        }
        foreach ($miniNode->xpath('./PSPACE|./P') as $pnode) {
          $val = trim(preg_replace('/\s+/u', ' ', (string) $pnode));
          if ($val !== '') {
            $miniTextParts[] = $val;
          }
        }
        if ($miniTextParts) {
          $lines[] = implode(' ', $miniTextParts);
        }
      }
    }

    $sectionNodes = $target->xpath('.//DIV8[@TYPE="SECTION"]');
    if (is_array($sectionNodes)) {
      foreach ($sectionNodes as $section) {
        $secHead = trim(preg_replace('/\s+/u', ' ', (string) ($section->HEAD ?? '')));
        if ($secHead !== '') {
          $lines[] = $secHead;
        }
        $paras = $section->xpath('./P|./PSPACE|./FP|./FP-1|./FP-2|./FP2|./FP2-2|./FP2-3|./P-1|./P-2|./P-3|./NOTE/P|./NOTE/PSPACE');
        if (is_array($paras)) {
          foreach ($paras as $p) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) $p));
            if ($text !== '') {
              $lines[] = $text;
            }
          }
        }
        foreach ($section->xpath('./CITA') as $cita) {
          $citaText = trim(preg_replace('/\s+/u', ' ', (string) $cita));
          if ($citaText !== '') {
            $lines[] = $citaText;
          }
        }
      }
    }

    $out = trim(implode("\n", $lines));
    if ($out === '') {
      return '';
    }
    $out = preg_replace('/\n+/', "\n", $out);
    return trim($out);
  }

  /**
   * Remove all stored agency and regulation entities and clear caches.
   */
  public function purgeStoredData(): void {
    $regulationStorage = $this->entityTypeManager->getStorage('ecfr_regulation');
    $regs = $regulationStorage->loadMultiple();
    if ($regs) {
      $regulationStorage->delete($regs);
    }

    $this->agencyService->deleteAllAgencies();
    $this->agencyService->clearAgencyCache();
  }

  /**
   * Check if the title XML has changed since last import.
   */
  protected function hasTitleXmlChanged(string $title): bool {
    $cid = 'ecfr_regulations:titles:' . $title;
    $result = $this->getLocalTitleXml($title);
    $xmlString = $result['xml'];
    if ($xmlString === NULL) {
      // No file, consider changed.
      return TRUE;
    }
    $currentChecksum = md5($xmlString);
    $cached = $this->cache->get($cid);
    if ($cached && $cached->data === $currentChecksum) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Update the cached checksum for the title XML after import.
   */
  public function updateTitleXmlChecksum(string $title): void {
    $cid = 'ecfr_regulations:titles:' . $title;
    $result = $this->getLocalTitleXml($title);
    $xmlString = $result['xml'];
    if ($xmlString !== NULL) {
      $checksum = md5($xmlString);
      // Cache for 1 days.
      $this->cache->set($cid, $checksum, time() + 86400 * 1, ['ecfr_regulations']);
    }
  }

  /**
   * Persist a regulation entity for the provided data.
   */
  protected function persistRegulationEntity(array $data, ?string $agencySlug): void {
    $hasChapter = isset($data['chapter']) && !empty($data['chapter']);
    $hasSubtitle = isset($data['subtitle']) && !empty($data['subtitle']);
    if (empty($data['title']) || (!$hasChapter && !$hasSubtitle) || !$agencySlug) {
      return;
    }
    $text = $data['raw_text'] ?? '';
    $checksum = $text ? md5($text) : '';
    $storage = $this->entityTypeManager->getStorage('ecfr_regulation');
    $properties = ['title' => $data['title']];
    if ($hasChapter) {
      $properties['chapter'] = $data['chapter'];
    }
    if ($hasSubtitle) {
      $properties['subtitle'] = $data['subtitle'];
    }
    $existing = $storage->loadByProperties($properties);
    $entity = $existing ? reset($existing) : $storage->create(['title' => $data['title']]);
    if ($hasChapter) {
      $entity->set('chapter', $data['chapter']);
    }
    if ($hasSubtitle) {
      $entity->set('subtitle', $data['subtitle']);
    }
    if ($existing && $entity->get('checksum')->value === $checksum) {
      return;
    }
    $entity->set('agency_slug', $agencySlug);
    $entity->set('raw_text', $text);
    $entity->set('word_count', \str_word_count($text));
    $entity->set('checksum', $checksum);
    $entity->set('changed', strtotime($data['latest_issue_date']));
    $entity->save();
    $this->updateAgencyAggregates($agencySlug, strtotime($data['latest_issue_date']));
    
  }

  /**
   * Update agency aggregated word counts and changed field.
   */
  protected function updateAgencyAggregates(string $agencySlug, int $latestIssueTimestamp): void {
    $agencyStorage = $this->entityTypeManager->getStorage('ecfr_agency');
    $agencies = $agencyStorage->loadByProperties(['slug' => $agencySlug]);
    if (!$agencies) {
      return;
    }
    $agency = reset($agencies);
    $regulationStorage = $this->entityTypeManager->getStorage('ecfr_regulation');
    $regs = $regulationStorage->loadByProperties(['agency_slug' => $agencySlug]);
    $total = array_sum(array_map(fn($r) => $r->get('word_count')->value ?? 0, $regs));
    $agency->set('word_count', $total);

    $currentChanged = $agency->get('changed')->value ?? 0;
    if ($currentChanged === NULL || $latestIssueTimestamp > $currentChanged) {
      $agency->set('changed', $latestIssueTimestamp);
    }
    $agency->save();
  }

  /**
   * Clear all title checksum caches.
   */
  public function clearTitleChecksums(): void {
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['ecfr_regulations']);
    $this->logger->info('Cleared all title checksum caches.');
  }

}
