<?php

namespace Drupal\ecfr_regulations;

use Drupal\Core\Url;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Psr\Log\LoggerInterface;

/**
 * Agency.
 */
class EcfrAgency {
  use StringTranslationTrait;

  /**
   * Client for retrieving eCFR data.
   */
  protected EcfrAPIClient $client;

  /**
   * Cache backend for agency data.
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
   * Logger channel service.
   */
  protected LoggerInterface $logger;

  /**
   * Date formatter service.
   */
  protected DateFormatterInterface $dateFormatter;

  public function __construct(
    EcfrAPIClient $client,
    CacheBackendInterface $cache,
    ConfigFactoryInterface $configFactory,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerInterface $logger,
    DateFormatterInterface $dateFormatter,
    TranslationInterface $translation,
  ) {
    $this->client = $client;
    $this->cache = $cache;
    $this->configFactory = $configFactory;
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $logger;
    $this->dateFormatter = $dateFormatter;
    $this->stringTranslation = $translation;
  }

  /**
   * Retrieve agencies from cache or remote API and persist entities.
   */
  public function getAgencies(): array {
    $cid = 'ecfr_regulations:agencies';
    if ($cache = $this->cache->get($cid)) {
      return is_array($cache->data) ? $cache->data : [];
    }

    try {
      $data = $this->client->fetchAgencies();
    }
    catch (\Throwable $e) {
      $this->logger->error('Agencies request failed: @message', ['@message' => $e->getMessage()]);
      return [];
    }

    $agencies = $data['agencies'] ?? [];
    $simplified = [];
    foreach ($agencies as $agency) {
      $normalized = EcfrUtilities::normalizeAgency($agency);
      $simplified[] = $normalized;
      $this->persistAgencyEntity($normalized);
    }

    $ttl = (int) $this->configFactory->get('ecfr_regulations.settings')->get('cache_lifetime') ?: 86400;
    $this->cache->set($cid, $simplified, time() + $ttl, ['ecfr_regulations']);

    return $simplified;
  }

  /**
   * Retrieve agencies with optional CFR title filtering.
   */
  public function listAgencies(?string $title = NULL): array {
    $agencies = $this->getAgencies();
    if ($title === NULL || $title === '') {
      return $agencies;
    }

    $titleString = (string) $title;
    return array_values(array_filter($agencies, fn(array $agency) =>
      array_filter($agency['cfr_references'] ?? [], fn(array $ref) => (string) ($ref['title'] ?? '') === $titleString) !== []
    ));
  }

  /**
   * Locate a single agency by slug.
   */
  public function getAgency(string $slug): ?array {
    foreach ($this->getAgencies() as $agency) {
      if (($agency['slug'] ?? '') === $slug) {
        return $agency;
      }
    }
    return NULL;
  }

  /**
   * Analytics summary for agencies/regulations.
   */
  public function getAgenciesAnalytics(): array {
    $agencyStorage = $this->entityTypeManager->getStorage('ecfr_agency');
    $agencies = $agencyStorage->loadMultiple();
    if (empty($agencies)) {
      $this->getAgencies();
      $agencies = $agencyStorage->loadMultiple();
    }
    $regulationStorage = $this->entityTypeManager->getStorage('ecfr_regulation');
    // Use count query instead of loading all entities.
    $regulationCount = $regulationStorage->getQuery()->accessCheck(FALSE)->count()->execute();
    $topLevelCount = 0;
    $subagencyCount = 0;
    $titles = [];
    foreach ($agencies as $agency) {
      if ($agency->get('parent')->isEmpty()) {
        $topLevelCount++;
      }
      else {
        $subagencyCount++;
      }
      $agencyTitles = $agency->get('titles')->value ? json_decode($agency->get('titles')->value, TRUE) ?: [] : [];
      $titles = array_merge($titles, $agencyTitles);
    }
    $titles = array_unique($titles);
    return [
      'agencies' => $agencies,
      'agency_count' => $topLevelCount,
      'regulation_count' => $regulationCount,
      'subagency_count' => $subagencyCount,
      'total_agency_count' => $topLevelCount + $subagencyCount,
      'titles_count' => count($titles),
    ];
  }

  /**
   * Build flattened rows for analytics tables.
   */
  public function buildAgencyTableRows(array $agencies): array {
    $tree = EcfrUtilities::buildAgencyTree($agencies);
    return EcfrUtilities::buildAgencyTableRows($tree, function ($agency, int $depth) {
      $indent = str_repeat('— ', $depth);
      return [
        'data' => [
          $indent . $agency->get('name')->value,
          $agency->get('word_count')->value,
          $agency->get('cfr_references')->value ? count(json_decode($agency->get('cfr_references')->value, TRUE) ?: []) : 0,
          $agency->get('titles')->value ? implode(', ', json_decode($agency->get('titles')->value, TRUE) ?: []) : '',
          $agency->get('changed')->value ? $this->dateFormatter->format($agency->get('changed')->value, 'ecfr_date_format') : '',
          [
            'data' => [
              '#type' => 'link',
              '#title' => $this->t('View'),
              '#url' => Url::fromRoute('ecfr_regulations.analytics_agency', ['slug' => $agency->get('slug')->value]),
            ],
          ],
        ],
      ];
    });
  }

  /**
   * Fetch analytics for a single agency.
   */
  public function getAgencyAnalytics(string $slug): ?array {
    $agencyStorage = $this->entityTypeManager->getStorage('ecfr_agency');
    $agencies = $agencyStorage->loadByProperties(['slug' => $slug]);
    $agency = $agencies ? reset($agencies) : NULL;
    if (!$agency) {
      return NULL;
    }
    $regulationStorage = $this->entityTypeManager->getStorage('ecfr_regulation');
    $regulations = $regulationStorage->loadByProperties(['agency_slug' => $slug]);
    return [
      'agency' => $agency,
      'regulations' => $regulations,
    ];
  }

  /**
   * Remove all agency entities.
   */
  public function deleteAllAgencies(): void {
    $agencyStorage = $this->entityTypeManager->getStorage('ecfr_agency');
    $agencies = $agencyStorage->loadMultiple();
    if ($agencies) {
      $agencyStorage->delete($agencies);
    }
  }

  /**
   * Clear the cached agencies listing.
   */
  public function clearAgencyCache(): void {
    $this->cache->delete('ecfr_regulations:agencies');
  }

  /**
   * Create or update an Agency content entity.
   */
  protected function persistAgencyEntity(array $normalized, ?int $parentId = NULL): void {
    if (empty($normalized['slug'])) {
      return;
    }
    $titles = array_unique(array_filter(array_column($normalized['cfr_references'], 'title')));
    $agencyStorage = $this->entityTypeManager->getStorage('ecfr_agency');
    $existing = $agencyStorage->loadByProperties(['slug' => $normalized['slug']]);
    $entity = $existing ? reset($existing) : $agencyStorage->create(['slug' => $normalized['slug']]);
    if (!$existing) {
      $entity->set('changed', NULL);
    }
    $entity->set('name', $normalized['name'] ?? '');
    $entity->set('cfr_references', json_encode($normalized['cfr_references']));
    $entity->set('titles', json_encode($titles));
    if ($parentId) {
      $parent = $agencyStorage->load($parentId);
      if ($parent) {
        $entity->set('parent', $parent);
      }
    }
    $entity->save();
    $agencyId = $entity->id();
    foreach ($normalized['children'] ?? [] as $child) {
      $this->persistAgencyEntity($child, $agencyId);
    }
  }

}
