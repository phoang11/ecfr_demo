<?php

namespace Drupal\ecfr_regulations;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Client service for interacting with the eCFR API and managing downloads.
 */
class EcfrAPIClient {

  /**
   * HTTP client for communicating with the eCFR API.
   */
  protected ClientInterface $httpClient;

  /**
   * Configuration factory service.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Logger channel for recording API activity.
   */
  protected LoggerInterface $logger;

  /**
   * File system service for storage operations.
   */
  protected FileSystemInterface $fileSystem;

  /**
   * File repository service.
   */
  protected FileRepositoryInterface $fileRepository;

  public function __construct(ClientInterface $httpClient, ConfigFactoryInterface $configFactory, LoggerInterface $logger, FileSystemInterface $fileSystem, FileRepositoryInterface $fileRepository) {
    $this->httpClient = $httpClient;
    $this->configFactory = $configFactory;
    $this->logger = $logger;
    $this->fileSystem = $fileSystem;
    $this->fileRepository = $fileRepository;
  }

  /**
   * Gets the configured base URL for eCFR API requests.
   */
  protected function getBase(): string {
    $config = $this->configFactory->get('ecfr_regulations.settings');
    $base = $config->get('api_base') ?: 'https://www.ecfr.gov';
    return rtrim($base, '/');
  }

  /**
   * Fetch agencies data from the eCFR API.
   *
   * @return array
   *   Decoded JSON array.
   *
   * @throws \RuntimeException
   *   When the request fails or yields an unexpected response.
   */
  public function fetchAgencies(): array {
    $url = $this->getBase() . '/api/admin/v1/agencies';
    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => ['Accept' => 'application/json'],
        'timeout' => 20,
      ]);
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('Failed to fetch agencies.', 0, $e);
    }

    if ($response->getStatusCode() !== 200) {
      throw new \RuntimeException('Unexpected response when fetching agencies. HTTP status: ' . $response->getStatusCode());
    }

    try {
      $data = Json::decode((string) $response->getBody());
    }
    catch (\InvalidArgumentException $jsonError) {
      throw new \RuntimeException('Failed to decode agencies response.', 0, $jsonError);
    }

    if (!is_array($data)) {
      throw new \RuntimeException('Failed to decode agencies response.');
    }

    return $data;
  }

  /**
   * Fetch titles data from the eCFR API.
   *
   * @return array
   *   Array of title information.
   *
   * @throws \RuntimeException
   *   When the request fails or yields an unexpected response.
   */
  public function fetchTitles(): array {
    $url = $this->getBase() . '/api/versioner/v1/titles.json';
    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => ['Accept' => 'application/json'],
        'timeout' => 30,
      ]);
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('Failed to fetch titles list.', 0, $e);
    }

    if ($response->getStatusCode() !== 200) {
      throw new \RuntimeException('Unexpected response when fetching titles list. HTTP status: ' . $response->getStatusCode());
    }

    try {
      $data = Json::decode((string) $response->getBody());
    }
    catch (\InvalidArgumentException $jsonError) {
      throw new \RuntimeException('Failed to decode titles list response.', 0, $jsonError);
    }

    if (!is_array($data) || !isset($data['titles'])) {
      throw new \RuntimeException('Invalid titles list response.');
    }

    return $data['titles'];
  }

  /**
   * Download a single title XML file.
   *
   * @param string $titleNumber
   *   The title number being downloaded.
   * @param string $snapshotDate
   *   The snapshot date associated with the title.
   * @param string $destinationDirectory
   *   The directory to save the XML file in.
   *
   * @return bool
   *   TRUE if the download and save succeeded, FALSE otherwise.
   */
  public function downloadTitleXml(string $titleNumber, string $snapshotDate, string $destinationDirectory): bool {
    $requestUrl = $this->getBase() . "/api/versioner/v1/full/{$snapshotDate}/title-{$titleNumber}.xml";
    $filename = "title-{$titleNumber}-{$snapshotDate}.xml";
    $destinationPath = $destinationDirectory . '/' . $filename;
    try {
      $response = $this->httpClient->request('GET', $requestUrl, [
        'headers' => ['Accept' => 'application/xml'],
        'timeout' => 120,
      ]);
      if ($response->getStatusCode() === 200) {
        $xmlContent = (string) $response->getBody();
        $this->fileRepository->writeData($xmlContent, $destinationPath, FileSystemInterface::EXISTS_REPLACE);
        $this->logger->notice('Downloaded title @title (@date) to @path', [
          '@title' => $titleNumber,
          '@date' => $snapshotDate,
          '@path' => $destinationPath,
        ]);
        return TRUE;
      }

      $this->logger->warning("Failed to download title {$titleNumber} ({$snapshotDate}): HTTP {$response->getStatusCode()}");
      return FALSE;
    }
    catch (\Throwable $exception) {
      $this->logger->warning("Failed to download title {$titleNumber} ({$snapshotDate}): " . $exception->getMessage());
      return FALSE;
    }
  }

  /**
   * Process downloading titles.
   *
   * @param array $titles
   *   The titles to download.
   * @param string|null $specificDate
   *   The specific date to use, if provided.
   * @param string $destinationDirectory
   *   The destination directory.
   *
   * @return array
   *   Array with 'success' and 'fail' counts.
   */
  private function processTitlesDownload(array $titles, ?string $specificDate, string $destinationDirectory): array {
    $successCount = 0;
    $failCount = 0;
    foreach ($titles as $titleMetadata) {
      $titleNumber = (string) ($titleMetadata['number'] ?? '');
      if ($titleNumber === '') {
        continue;
      }
      if (!empty($titleMetadata['reserved'])) {
        $this->logger->notice('Reserved title: @title', ['@title' => $titleNumber]);
        continue;
      }
      $titleDate = $specificDate ?? ($titleMetadata['latest_issue_date'] ?? $titleMetadata['up_to_date_as_of'] ?? NULL);
      if (!EcfrUtilities::isValidDateFormat($titleDate)) {
        $this->logger->warning("Invalid date '@date' for title @num", ['@date' => $titleDate, '@num' => $titleNumber]);
        $failCount++;
        continue;
      }
      $filename = "title-{$titleNumber}-{$titleDate}.xml";
      $destinationPath = $destinationDirectory . '/' . $filename;
      if (file_exists($this->fileSystem->realpath($destinationPath))) {
        $this->logger->notice('File already exists: @path', ['@path' => $destinationPath]);
        continue;
      }
      if ($this->downloadTitleXml($titleNumber, $titleDate, $destinationDirectory)) {
        $successCount++;
      }
      else {
        $failCount++;
      }
    }
    return ['success' => $successCount, 'fail' => $failCount];
  }

  /**
   * Get eCFR title(s) XML file.
   *
   * @param string|null $titleNumber
   *   The title number to get, or null to get all titles.
   * @param string|null $specificDate
   *   The specific date to download titles for, in YYYY-MM-DD format.
   */
  public function getTitleXml(?string $titleNumber = NULL, ?string $specificDate = NULL): void {
    $this->logger->notice($titleNumber ? 'Starting download of eCFR title @title.' : 'Starting bulk download of eCFR titles.', ['@title' => $titleNumber]);

    // Fetch titles list.
    try {
      $titles = $this->fetchTitles();
    }
    catch (\RuntimeException $e) {
      $this->logger->error($e->getMessage());
      return;
    }

    // Filter titles.
    if ($titleNumber !== NULL) {
      $titles = array_filter($titles, function (array $titleMetadata) use ($titleNumber) {
        return ((string) ($titleMetadata['number'] ?? '')) === $titleNumber;
      });
    }
    if (empty($titles)) {
      $this->logger->error('Title @title not found in titles list.', ['@title' => $titleNumber]);
      return;
    }

    // Validate specific date.
    if (!EcfrUtilities::isValidDateFormat($specificDate)) {
      $this->logger->error('Invalid specific date format: @date. Use YYYY-MM-DD.', ['@date' => $specificDate]);
      return;
    }

    $destinationDirectory = 'public://ecfr_titles';
    // Prepare destination directory.
    if (!$this->fileSystem->prepareDirectory($destinationDirectory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $this->logger->error('Unable to prepare directory @dir for title downloads.', ['@dir' => $destinationDirectory]);
      return;
    }

    // Process downloads.
    $counts = $this->processTitlesDownload($titles, $specificDate, $destinationDirectory);
    $this->logger->info("Downloaded {$counts['success']} title(s); {$counts['fail']} failures.");
  }

  /**
   * Batch process title downloads.
   */
  public static function batchDownloadTitles(array $titleBatch, array &$context): void {
    $apiClient = \Drupal::service('ecfr_regulations.client');

    if (!isset($context['results']['success_count'])) {
      $context['results']['success_count'] = 0;
    }
    if (!isset($context['results']['fail_count'])) {
      $context['results']['fail_count'] = 0;
    }
    if (!isset($context['results']['skipped_count'])) {
      $context['results']['skipped_count'] = 0;
    }

    $destinationDirectory = 'public://ecfr_titles';
    // Ensure directory exists.
    if (!$apiClient->fileSystem->prepareDirectory($destinationDirectory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $context['results']['fail_count'] += count($titleBatch);
      $context['message'] = 'Failed to prepare destination directory.';
      return;
    }

    foreach ($titleBatch as $titleMetadata) {
      $titleNumber = (string) ($titleMetadata['number'] ?? '');
      if ($titleNumber === '') {
        continue;
      }
      if (!empty($titleMetadata['reserved'])) {
        continue;
      }
      $titleDate = $titleMetadata['latest_issue_date'] ?? $titleMetadata['up_to_date_as_of'] ?? NULL;
      if (!EcfrUtilities::isValidDateFormat($titleDate)) {
        $context['results']['fail_count']++;
        continue;
      }
      $filename = "title-{$titleNumber}-{$titleDate}.xml";
      $destinationPath = $destinationDirectory . '/' . $filename;
      if (file_exists($apiClient->fileSystem->realpath($destinationPath))) {
        // File already exists, count as skipped.
        $context['results']['skipped_count']++;
        continue;
      }
      if ($apiClient->downloadTitleXml($titleNumber, $titleDate, $destinationDirectory)) {
        $context['results']['success_count']++;
      }
      else {
        $context['results']['fail_count']++;
      }
    }

    $context['message'] = 'Downloaded ' . $context['results']['success_count'] . ' titles, skipped ' . $context['results']['skipped_count'] . ' existing, ' . $context['results']['fail_count'] . ' failures.';
  }

  /**
   * Batch finished callback for title downloads.
   */
  public static function batchDownloadFinished($success, $results, $operations): void {
    if ($success) {
      \Drupal::logger('ecfr_regulations')->info('Title downloaded: @downloaded, Skipped: @skipped, Failures: @fail.', [
        '@downloaded' => $results['success_count'] ?? 0,
        '@skipped' => $results['skipped_count'] ?? 0,
        '@fail' => $results['fail_count'] ?? 0,
      ]);

      \Drupal::messenger()->addStatus('Title download completed successfully. Downloaded ' . ($results['success_count'] ?? 0) . ' titles, skipped ' . ($results['skipped_count'] ?? 0) . ' existing, ' . ($results['fail_count'] ?? 0) . ' failures.');
    }
    else {
      \Drupal::logger('ecfr_regulations')->error('Batch title download failed.');
      \Drupal::messenger()->addError('Title download failed. Check the logs for details.');
    }
  }

}
