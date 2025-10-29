<?php

namespace Drupal\ecfr_regulations\Controller;

use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\ecfr_regulations\EcfrAgency;
use Drupal\ecfr_regulations\EcfrAPIClient;
use Drupal\ecfr_regulations\EcfrRegulation;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;

/**
 * HTML analytics pages for eCFR data.
 */
class EcfrAnalyticsController extends ControllerBase {

  /**
   * Service handling agency operations.
   */
  protected EcfrAgency $agencyService;

  /**
   * Service handling regulation operations.
   */
  protected EcfrRegulation $regulationService;

  /**
   * Client service for API requests.
   */
  protected EcfrAPIClient $apiClient;

  /**
   * File system service.
   */
  protected FileSystemInterface $fileSystem;

  /**
   * File repository service.
   */
  protected FileRepositoryInterface $fileRepository;

  /**
   * File URL generator service.
   */
  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->agencyService = $container->get('ecfr_regulations.agency_service');
    $instance->regulationService = $container->get('ecfr_regulations.regulation_service');
    $instance->apiClient = $container->get('ecfr_regulations.client');
    $instance->fileSystem = $container->get('file_system');
    $instance->fileRepository = $container->get('file.repository');
    $instance->fileUrlGenerator = $container->get('file_url_generator');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * Agencies analytics list.
   */
  public function listAgencies(): array {
    $data = $this->agencyService->getAgenciesAnalytics();
    $rows = $this->agencyService->buildAgencyTableRows($data['agencies']);
    return [
      'summary' => [
        '#markup' => $this->t('Agencies: @agencies | Subagencies: @subagencies | Regulations: @regulations | <a href=":url">Titles</a>: @titles', [
          '@agencies' => $data['agency_count'],
          '@subagencies' => $data['subagency_count'] ?? 0,
          '@regulations' => $data['regulation_count'],
          '@titles' => $data['titles_count'] ?? 0,
          ':url' => '/ecfr/titles',
        ]),
        '#allowed_tags' => ['a'],
      ],
      'table' => [
        '#type' => 'table',
        '#attributes' => [
          'class' => ['usa-table', 'usa-table--sortable'],
        ],
        '#header' => [
          [
            'data' => $this->t('Name'),
            'data-sortable' => 'true',
            'scope' => 'col',
          ],
          [
            'data' => $this->t('Word count'),
            'data-sortable' => 'true',
            'scope' => 'col',
          ],
          [
            'data' => $this->t('Regulation'),
            'data-sortable' => 'true',
            'scope' => 'col',
          ],
          [
            'data' => $this->t('Titles'),
            'data-sortable' => 'true',
            'scope' => 'col',
          ],
          [
            'data' => $this->t('Latest Updated'),
            'data-sortable' => 'true',
            'scope' => 'col',
          ],
          [
            'data' => $this->t('Operations'),
            'data-sortable' => 'false',
            'scope' => 'col',
          ],
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No agencies imported yet. Run drush ecfr:import'),
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Agency detail analytics: metrics history + regulations list.
   */
  public function agencyDetail(string $slug): array {
    $data = $this->agencyService->getAgencyAnalytics($slug);
    if (!$data) {
      return ['#markup' => $this->t('Agency not found.')];
    }
    $agency = $data['agency'];
    $regulationRows = $this->regulationService->buildRegulationTableRows($data['regulations'], $slug);

    return [
      'header' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $agency->get('name')->value,
      ],
      'regs' => [
        '#type' => 'table',
        '#attributes' => [
          'class' => ['usa-table', 'usa-table--sortable'],
        ],
        '#header' => [
          [
            'data' => $this->t('Title'),
            'data-sortable' => 'true',
            'scope' => 'col',
          ],
          [
            'data' => $this->t('Chapter/Subtitle'),
            'data-sortable' => 'true',
            'scope' => 'col',
          ],
          [
            'data' => $this->t('Word count'),
            'data-sortable' => 'true',
            'scope' => 'col',
          ],
          [
            'data' => $this->t('Latest Updated'),
            'data-sortable' => 'true',
            'scope' => 'col',
          ],
          [
            'data' => $this->t('Checksum'),
            'data-sortable' => 'true',
            'scope' => 'col',
          ],
        ],
        '#rows' => $regulationRows,
        '#empty' => $this->t('No regulations imported for this agency yet.'),
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * View raw text of a regulation.
   */
  public function viewRegulationRaw(string $agencySlug, int $regulationId): array {
    $data = $this->regulationService->getRegulationRaw($agencySlug, $regulationId);
    if (!$data) {
      return ['#markup' => $this->t('Regulation not found.')];
    }
    $identifier = $data['chapter'] ?? $data['subtitle'];
    $type = isset($data['chapter']) ? 'Chapter' : 'Subtitle';

    // Load the regulation entity for text analysis.
    $regulationStorage = $this->entityTypeManager->getStorage('ecfr_regulation');
    $regulation = $regulationStorage->load($regulationId);
    $analysis = $regulation ? $regulation->getTextAnalysis() : [
      'frequencies' => [],
      'lexical_diversity' => ['ttr' => 0, 'unique_words' => 0, 'total_words' => 0],
    ];

    // Build text analysis section.
    $analysisRows = [];
    $count = 0;
    foreach ($analysis['frequencies'] as $word => $freq) {
      // Limit to top 20.
      if ($count >= 20) {
        break;
      }
      $analysisRows[] = [
        'word' => $word,
        'frequency' => $freq,
      ];
      $count++;
    }

    $diversity = $analysis['lexical_diversity'];

    return [
      'header' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Title @title - @type @identifier', [
          '@title' => $data['title'],
          '@type' => $type,
          '@identifier' => $identifier,
        ]),
      ],
      'analysis' => [
        '#type' => 'details',
        '#title' => $this->t('Text Analysis'),
        '#open' => FALSE,
        'summary' => [
          '#markup' => $this->t('<strong>Lexical Diversity:</strong> Type-Token Ratio: @ttr, Unique Words: @unique, Total Words: @total', [
            '@ttr' => $diversity['ttr'],
            '@unique' => $diversity['unique_words'],
            '@total' => $diversity['total_words'],
          ]),
        ],
        'frequency_table' => [
          '#type' => 'table',
          '#attributes' => [
            'class' => ['usa-table', 'usa-table--sortable'],
          ],
          '#caption' => $this->t('Word Frequencies'),
          '#header' => [
            [
              'data' => $this->t('Word'),
              'data-sortable' => 'true',
              'scope' => 'col',
            ],
            [
              'data' => $this->t('Frequency'),
              'data-sortable' => 'true',
              'scope' => 'col',
            ],
          ],
          '#rows' => $analysisRows,
          '#empty' => $this->t('No frequency data available.'),
        ],
      ],
      'content' => [
        '#type' => 'html_tag',
        '#tag' => 'pre',
        '#attributes' => [
          'style' => 'white-space: pre-wrap; word-break: break-word;',
        ],
        '#value' => Html::escape($data['text']),
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * List all eCFR titles with their latest change dates.
   */
  public function listTitles(): array {
    try {
      $titles = $this->apiClient->fetchTitles();
    }
    catch (\Exception $e) {
      return [
        '#markup' => $this->t('Failed to fetch titles: @error', ['@error' => $e->getMessage()]),
      ];
    }

    // Limit titles for memory efficiency - show first 100.
    $titles = array_slice($titles, 0, 100);

    $rows = [];
    foreach ($titles as $title) {
      $titleNumber = $title['number'] ?? '';
      $latestAmended = $title['latest_amended_on'] ?? '';
      $latestIssue = $title['latest_issue_date'] ?? '';

      // Check if local file exists for this specific title.
      $localFile = $this->findLocalTitleFile($titleNumber);
      $localXmlCell = $localFile ?
        [
          'data' => [
            '#type' => 'link',
            '#title' => basename($localFile),
            '#url' => Url::fromUri($this->fileUrlGenerator->generateAbsoluteString($localFile)),
            '#attributes' => ['target' => '_blank'],
          ],
        ] :
        $this->t('Not available');

      $rows[] = [
        $titleNumber,
        $title['name'] ?? '',
        $latestAmended,
        $latestIssue,
        $localXmlCell,
      ];
    }

    return [
      'summary' => [
        '#markup' => $this->t('Total titles: @count', ['@count' => count($titles)]),
      ],
      'table' => [
        '#type' => 'table',
        '#attributes' => [
          'class' => ['usa-table', 'usa-table--sortable'],
        ],
        '#header' => [
          [
            'data' => $this->t('Title Number'),
            'data-sortable' => 'true',
            'scope' => 'col',
          ],
          [
            'data' => $this->t('Title Name'),
            'data-sortable' => 'true',
            'scope' => 'col',
          ],
          [
            'data' => $this->t('Latest Amended On'),
            'data-sortable' => 'true',
            'scope' => 'col',
          ],
          [
            'data' => $this->t('Latest Issue Date'),
            'data-sortable' => 'true',
            'scope' => 'col',
          ],
          [
            'data' => $this->t('XML File'),
            'data-sortable' => 'false',
            'scope' => 'col',
          ],
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No titles found.'),
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Find the most recent local XML file for a specific title number.
   */
  protected function findLocalTitleFile(string $titleNumber): ?string {
    $directory = 'public://ecfr_titles';

    // Ensure directory exists.
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
      return NULL;
    }

    // Get the real path for more reliable file operations.
    $realPath = $this->fileSystem->realpath($directory);
    if (!$realPath || !is_dir($realPath)) {
      return NULL;
    }

    // Look for files matching the pattern for this title.
    $pattern = '/^title-' . preg_quote($titleNumber, '/') . '-(.+)\.xml$/';
    $fileList = $this->fileSystem->scanDirectory($directory, $pattern);

    if (empty($fileList)) {
      return NULL;
    }

    // Find the most recent file by date in filename.
    $latestFile = NULL;
    $latestDate = '';

    foreach ($fileList as $file) {
      if (preg_match($pattern, $file->filename, $matches)) {
        $date = $matches[1];
        if ($date > $latestDate) {
          $latestDate = $date;
          $latestFile = $file->uri;
        }
      }
    }

    // Double-check that the file actually exists on disk.
    if ($latestFile && !$this->fileSystem->realpath($latestFile)) {
      return NULL;
    }

    return $latestFile;
  }

}
