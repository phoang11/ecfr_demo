<?php

namespace Drupal\ecfr_regulations\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for eCFR regulations API endpoints.
 */
class EcfrController extends ControllerBase {

  /**
   * Lists agencies, optionally filtered by title.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response with agencies list.
   */
  public function listAgencies(Request $request): JsonResponse {
    $title = $request->query->get('title');
    $agencyStorage = $this->entityTypeManager()->getStorage('ecfr_agency');
    $query = $agencyStorage->getQuery()->accessCheck(FALSE);
    if ($title) {
      // Filter by title in the titles JSON field.
      $query->condition('titles', '%' . $title . '%', 'LIKE');
    }
    $ids = $query->execute();
    $agencies = $agencyStorage->loadMultiple($ids);

    $dateFormatter = \Drupal::service('date.formatter');
    $agencyData = [];
    foreach ($agencies as $agency) {
      $agencyData[] = [
        'id' => $agency->id(),
        'uuid' => $agency->uuid(),
        'name' => $agency->get('name')->value,
        'slug' => $agency->get('slug')->value,
        'short_name' => $agency->get('short_name')->value,
        'cfr_references' => $agency->get('cfr_references')->value ? json_decode($agency->get('cfr_references')->value, true) : [],
        'titles' => $agency->get('titles')->value ? json_decode($agency->get('titles')->value, true) : [],
        'word_count' => $agency->get('word_count')->value,
        'parent' => $agency->get('parent')->target_id ?? null,
        'last_changed' => $agency->get('changed')->value ? $dateFormatter->format($agency->get('changed')->value, 'ecfr_date_format') : null,
        'created' => $agency->get('created')->value ? $dateFormatter->format($agency->get('created')->value, 'ecfr_date_format') : null,
      ];
    }

    return new JsonResponse([
      'count' => count($agencyData),
      'agencies' => $agencyData,
    ]);
  }

  /**
   * Gets regulations for a specific agency by slug.
   *
   * @param string $slug
   *   The agency slug.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response with regulations data or error.
   */
  public function getAgency(string $slug): JsonResponse {
    $regulationStorage = $this->entityTypeManager()->getStorage('ecfr_regulation');
    $query = $regulationStorage->getQuery()->accessCheck(FALSE);
    $query->condition('agency_slug', $slug);
    $ids = $query->execute();
    $regulations = $regulationStorage->loadMultiple($ids);

    $dateFormatter = \Drupal::service('date.formatter');
    $regulationData = [];
    foreach ($regulations as $regulation) {
      $regulationData[] = [
        'id' => $regulation->id(),
        'uuid' => $regulation->uuid(),
        'title' => $regulation->get('title')->value,
        'chapter' => $regulation->get('chapter')->value,
        'subtitle' => $regulation->get('subtitle')->value,
        'agency_slug' => $regulation->get('agency_slug')->value,
        'view_text_url' => Url::fromRoute('ecfr_regulations.analytics_regulation_text', ['agencySlug' => $slug, 'regulationId' => $regulation->id()])->setAbsolute()->toString(),
        'word_count' => $regulation->get('word_count')->value,
        'checksum' => $regulation->get('checksum')->value,
        'text_analysis' => [
          // 'frequencies' => array_slice($regulation->getTextAnalysis()['frequencies'], 0, 10),
          'lexical_diversity' => $regulation->getTextAnalysis()['lexical_diversity'],
        ],
        'last_changed' => $regulation->get('changed')->value ? $dateFormatter->format($regulation->get('changed')->value, 'ecfr_date_format') : null,
        'created' => $regulation->get('created')->value ? $dateFormatter->format($regulation->get('created')->value, 'ecfr_date_format') : null,
      ];
    }

    return new JsonResponse([
      'count' => count($regulationData),
      'regulations' => $regulationData,
    ]);
  }

  /**
   * Lists eCFR titles with their metadata.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response with titles list.
   */
  public function listTitles(): JsonResponse {
    $apiClient = \Drupal::service('ecfr_regulations.client');
    $fileSystem = \Drupal::service('file_system');
    $fileUrlGenerator = \Drupal::service('file_url_generator');

    try {
      $titles = $apiClient->fetchTitles();
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => 'Failed to fetch titles: ' . $e->getMessage()], 500);
    }

    $titleData = [];
    foreach ($titles as $title) {
      $titleNumber = $title['number'] ?? '';
      $localXmlUrl = $this->findLocalTitleFile($titleNumber, $fileSystem, $fileUrlGenerator);

      $titleData[] = [
        'number' => $titleNumber,
        'name' => $title['name'] ?? '',
        'latest_amended_on' => $title['latest_amended_on'] ?? '',
        'latest_issue_date' => $title['latest_issue_date'] ?? '',
        'xml_file_url' => $localXmlUrl,
      ];
    }

    return new JsonResponse([
      'count' => count($titleData),
      'titles' => $titleData,
    ]);
  }

  /**
   * Finds the local XML file for a given title number.
   *
   * @param string $titleNumber
   *   The title number.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator service.
   *
   * @return string|null
   *   The absolute URL to the local XML file, or null if not found.
   */
  protected function findLocalTitleFile(string $titleNumber, $fileSystem, $fileUrlGenerator): ?string {
    $directory = 'public://ecfr_titles';

    // Ensure directory exists.
    if (!$fileSystem->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY)) {
      return null;
    }

    // Get the real path for more reliable file operations.
    $realPath = $fileSystem->realpath($directory);
    if (!$realPath || !is_dir($realPath)) {
      return null;
    }

    // Look for files matching the pattern for this title.
    $pattern = '/^title-' . preg_quote($titleNumber, '/') . '-(.+)\.xml$/';
    $fileList = $fileSystem->scanDirectory($directory, $pattern);

    if (empty($fileList)) {
      return null;
    }

    // Find the most recent file by date in filename.
    $latestFile = null;
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
    if ($latestFile && !$fileSystem->realpath($latestFile)) {
      return null;
    }

    return $latestFile ? $fileUrlGenerator->generateAbsoluteString($latestFile) : null;
  }

}
