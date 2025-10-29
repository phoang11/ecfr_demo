<?php

namespace Drupal\ecfr_regulations\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ecfr_regulations\EcfrAPIClient;
use Drupal\ecfr_regulations\EcfrRegulation;
use Drupal\ecfr_regulations\EcfrAgency;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for eCFR settings.
 */
class EcfrSettingsForm extends ConfigFormBase {

  /**
   * Client service for eCFR API access.
   */
  protected EcfrAPIClient $apiClient;

  /**
   * Regulation service for imports.
   */
  protected EcfrRegulation $regulationService;

  /**
   * Agency service for imports.
   */
  protected EcfrAgency $agencyService;

  /**
   * Constructs the form.
   */
  public function __construct(EcfrAPIClient $apiClient, EcfrRegulation $regulationService, EcfrAgency $agencyService) {
    $this->apiClient = $apiClient;
    $this->regulationService = $regulationService;
    $this->agencyService = $agencyService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ecfr_regulations.client'),
      $container->get('ecfr_regulations.regulation_service'),
      $container->get('ecfr_regulations.agency_service'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ecfr_regulations.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ecfr_regulations_settings_form';
  }

   /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form array with the form elements.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $config = $this->config('ecfr_regulations.settings');

    $form['api_base'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Base URL'),
      '#default_value' => $config->get('api_base') ?? 'https://www.ecfr.gov',
      '#description' => $this->t('Override only if eCFR base changes.'),
      '#required' => TRUE,
    ];

    $form['cache_lifetime'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache lifetime (seconds)'),
      '#default_value' => $config->get('cache_lifetime') ?? 86400,
      '#min' => 60,
      '#description' => $this->t('Time agencies data stays cached before refresh. Default 86400.'),
      '#required' => TRUE,
    ];

    $form['download_titles_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Download Titles'),
      '#description' => $this->t('Download all eCFR title XML files to the local filesystem. <a href=":url" target="_blank">View download status</a>. <br /> Files will be saved in the <code>public://ecfr_titles</code> directory.', [
        ':url' => '/ecfr/titles',
      ]),

    ];
    $form['download_titles_section']['download_titles'] = [
      '#type' => 'submit',
      '#value' => $this->t('Download'),
      '#submit' => ['::downloadTitlesSubmit'],
    ];

    $form['import_regulations_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Import Regulations'),
      '#description' => $this->t('Import all agencies and their regulations from the eCFR API into Drupal entities.'),
    ];
    $form['import_regulations_section']['import_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Import Limit'),
      '#description' => $this->t('Limit the number of agencies to import (leave empty for all). Useful for testing.'),
      '#min' => 1,
      '#step' => 1,
    ];
    $form['import_regulations_section']['import_regulations'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#submit' => ['::importRegulationsSubmit'],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $formState): void {
    parent::submitForm($form, $formState);
    $this->configFactory->getEditable('ecfr_regulations.settings')
      ->set('cache_lifetime', (int) $formState->getValue('cache_lifetime'))
      ->set('api_base', trim($formState->getValue('api_base')))
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $formState): void {
    $apiBase = $formState->getValue('api_base');
    if (!filter_var($apiBase, FILTER_VALIDATE_URL)) {
      $formState->setErrorByName('api_base', $this->t('API Base URL must be a valid URL.'));
    }
    $cacheLifetime = $formState->getValue('cache_lifetime');
    if ($cacheLifetime < 60) {
      $formState->setErrorByName('cache_lifetime', $this->t('Cache lifetime must be at least 60 seconds.'));
    }
  }

  /**
   * Submit handler for downloading titles.
   */
  public function downloadTitlesSubmit(array &$form, FormStateInterface $formState): void {
    try {
      $titles = $this->apiClient->fetchTitles();
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to fetch titles: @error', ['@error' => $e->getMessage()]));
      return;
    }

    // Create batch operations - process titles in chunks of 5 for downloads.
    $operations = [];
    $titleChunks = array_chunk($titles, 5);

    foreach ($titleChunks as $chunk) {
      $operations[] = [
        [EcfrAPIClient::class, 'batchDownloadTitles'],
        [$chunk],
      ];
    }

    $batch = [
      'title' => $this->t('Downloading eCFR Titles'),
      'operations' => $operations,
      'finished' => [EcfrAPIClient::class, 'batchDownloadFinished'],
      'progress_message' => $this->t('Processing @current out of @total batches.'),
    ];

    batch_set($batch);
  }

  /**
   * Submit handler for importing regulations.
   */
  public function importRegulationsSubmit(array &$form, FormStateInterface $formState): void {
    $agencies = $this->agencyService->getAgencies();
    
    // Apply limit if specified.
    $limit = $formState->getValue('import_limit');
    if (!empty($limit) && is_numeric($limit)) {
      $agencies = array_slice($agencies, 0, (int) $limit);
    }

    // Create batch operations - process agencies in chunks of 5.
    $operations = [];
    $agencyChunks = array_chunk($agencies, 5);

    foreach ($agencyChunks as $chunk) {
      $operations[] = [
        [EcfrRegulation::class, 'batchImportAgencies'],
        [$chunk],
      ];
    }

    $batch = [
      'title' => $this->t('Importing eCFR Regulations'),
      'operations' => $operations,
      'finished' => [EcfrRegulation::class, 'batchImportFinished'],
      'progress_message' => $this->t('Processing @current out of @total batches.'),
    ];

    batch_set($batch);
  }

}
