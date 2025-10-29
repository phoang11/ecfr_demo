<?php

namespace Drupal\ecfr_regulations\Drush\Commands;

use Drupal\Core\File\FileSystemInterface;
use Drupal\ecfr_regulations\EcfrAgency;
use Drupal\ecfr_regulations\EcfrAPIClient;
use Drupal\ecfr_regulations\EcfrRegulation;
use Drupal\ecfr_regulations\EcfrUtilities;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for eCFR regulations module.
 */
class EcfrRegulationsCommands extends DrushCommands {

  public function __construct(
    protected EcfrAgency $agencyService,
    protected EcfrRegulation $regulationService,
    protected EcfrAPIClient $client,
    protected FileSystemInterface $fileSystem,
  ) {}

  /**
   * Warm agencies cache and output count.
   *
   * @command ecfr:warm
   */
  public function warm(): void {
    $agencies = $this->agencyService->getAgencies();
    $this->logger()->success('eCFR agencies cached. Count: ' . count($agencies));
  }

  /**
   * Import agencies and regulations into entities.
   *
   * @command ecfr:import
   * @option limit Limit number of agencies to import (for dev/testing)
   */
  public function import(array $options = ['limit' => NULL]): void {
    $this->regulationService->import($options);
  }

  /**
   * Get eCFR title(s) XML file.
   *
   * @command ecfr:get-title-xml
   * @aliases ecfr-gtx
   * @option title Title number to get (optional, gets all if not specified)
   * @option date Specific date to download titles for, in YYYY-MM-DD format or 'current' (optional)
   * Example: drush ecfr:get-title-xml --title=1 --date=2023-10-01
   */
  public function getTitleXml(array $options = ['title' => NULL, 'date' => NULL]): void {
    $this->client->getTitleXml($options['title'], $options['date']);
  }

  /**
   * Purge all stored eCFR data (agencies, regulations, snapshots) to enable uninstall.
   *
   * @command ecfr:purge
   * @aliases ecfr-purge
   * @option force Skip confirmation.
   */
  public function purge(array $options = []): void {
    if (empty($options['force'])) {
      if (!$this->io()->confirm('This will DELETE all eCFR agencies and regulations. Continue?')) {
        $this->logger()->warning('Abort purge.');
        return;
      }
    }
    $this->regulationService->purgeStoredData();
    $this->logger()->success('All eCFR data purged. You can now uninstall the module (drush pmu ecfr_regulations -y).');
  }

  /**
   * Extract a chapter's aggregated text from a locally stored Title XML file.
   *
   * @command ecfr:chapter-extract
   * @aliases ecfr-ce
   * @param string $title
   *   The numeric Title number (e.g. 7)
   * @param string $chapter
   *   The chapter designator (e.g. I, II, III)
   *
   * @option date Snapshot date (YYYY-MM-DD) to use; defaults to latest local file.
   * @option output Write result to a file path instead of stdout.
   */
  public function chapterExtract(string $title, string $chapter, array $options = []): void {
    $date = $options['date'] ?? NULL;
    $normalizedChapter = EcfrUtilities::normalizeChapterIdentifier($chapter);
    if (preg_match('/^\d+$/', $chapter)) {
      $roman = EcfrUtilities::intToRoman((int) $chapter);
      if ($roman !== '') {
        $normalizedChapter = $roman;
      }
    }
    // Directory resolution is handled in downloadTitle; no need here unless writing output.
    $text = $this->regulationService->extractChapterFromLocalXml($title, $chapter, NULL, $date);
    if ($text === '') {
      $this->logger()->warning('No text extracted for Title ' . $title . ' Chapter ' . $normalizedChapter . ' (input ' . $chapter . ')');
      return;
    }
    if (!empty($options['output'])) {
      $dest = $this->resolveDestination($options['output']);
      if (!$this->writeOutput($dest, $text)) {
        $this->logger()->error('Failed writing output file ' . $dest);
        return;
      }
      $this->logger()->success('Chapter text written to ' . $dest . ' (bytes ' . strlen($text) . ')');
    }
    else {
      $this->output()->writeln($text);
      $this->logger()->success('Chapter text output complete (chapter ' . $normalizedChapter . ', bytes ' . strlen($text) . ').');
    }
  }

  /**
   * Resolves the destination path.
   *
   * @param string $destination
   *   The destination path.
   *
   * @return string
   *   The resolved path.
   */
  protected function resolveDestination(string $destination): string {
    if (str_contains($destination, '://')) {
      return $destination;
    }
    if (!str_starts_with($destination, '/')) {
      $destination = getcwd() . DIRECTORY_SEPARATOR . $destination;
    }
    return $destination;
  }

  /**
   * Writes contents to a file.
   *
   * @param string $destination
   *   The destination path.
   * @param string $contents
   *   The contents to write.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  protected function writeOutput(string $destination, string $contents): bool {
    $directory = $this->fileSystem->dirname($destination);
    if ($directory && !$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      return FALSE;
    }
    return $this->fileSystem->saveData($contents, $destination, FileSystemInterface::EXISTS_REPLACE) !== FALSE;
  }

  /**
   * Clear all cached eCFR data (agencies, regulations, titles).
   *
   * @command ecfr:clear-cache
   * @aliases ecfr-cc
   */
  public function clearCache(): void {
    $this->agencyService->clearAgencyCache();
    $this->regulationService->clearTitleChecksums();
    $this->logger()->success('All eCFR caches cleared.');
  }

}
