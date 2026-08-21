<?php

declare(strict_types=1);

namespace Drupal\voting_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Voting settings.
 *
 * This config form feeds:
 * - VoteManager (global enable/disable, anonymous voting)
 * - QuestionManager (default results visibility, TTL – if enabled).
 */
class VotingSettingsForm extends ConfigFormBase {

  /**
   * Gets the editable config names.
   *
   * @return string[]
   *   The editable config names.
   */
  protected function getEditableConfigNames(): array {
    return ['voting_core.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'voting_core_settings';
  }

  /**
   * Builds the voting settings form.
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form array.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('voting_core.settings');

    // Voting settings section.
    $form['voting'] = [
      '#type' => 'details',
      '#title' => $this->t('Voting Settings'),
      '#open' => TRUE,
    ];

    $form['voting']['voting_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable voting system'),
      '#description' => $this->t('When disabled, all voting functionality will be blocked both in the CMS and external API. Questions remain visible but voting is not allowed.'),
      '#default_value' => $config->get('voting_enabled') ?? TRUE,
    ];

    $form['voting']['allow_anonymous_voting'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow anonymous voting'),
      '#description' => $this->t('When enabled, anonymous users can vote. Note: This requires additional tracking logic to prevent duplicate votes (IP-based, cookie-based, etc.).'),
      '#default_value' => $config->get('allow_anonymous_voting') ?? FALSE,
    ];

    $form['results'] = [
      '#type' => 'details',
      '#title' => $this->t('Results Display'),
      '#open' => TRUE,
    ];

    $form['results']['show_results_by_default'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show results by default'),
      '#description' => $this->t('Default value for the "show results" field when creating new questions. Individual questions can override this setting.'),
      '#default_value' => $config->get('show_results_by_default') ?? TRUE,
    ];

    $form['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Performance Settings'),
      '#open' => FALSE,
    ];

    $form['performance']['cache_results_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Results cache TTL (seconds)'),
      '#description' => $this->t('How long to cache vote results. Set to 0 to disable caching. Recommended: 300 (5 minutes).'),
      '#default_value' => $config->get('cache_results_ttl') ?? 300,
      '#min' => 0,
      '#max' => 3600,
    ];

    $form['performance']['max_votes_per_hour'] = [
      '#type' => 'number',
      '#title' => $this->t('Max votes per user per hour'),
      '#description' => $this->t('Limits how many votes a user can cast per hour. Set to 0 to disable this limit.'),
      '#default_value' => $config->get('max_votes_per_hour') ?? 0,
      '#min' => 0,
    ];

    $form['api'] = [
      '#type' => 'details',
      '#title' => $this->t('API & External Sync'),
      '#open' => FALSE,
    ];

    $form['api']['api_rate_limit_per_ip'] = [
      '#type' => 'number',
      '#title' => $this->t('API rate limit per IP'),
      '#description' => $this->t('Maximum number of API requests allowed per IP within the rate limit window.'),
      '#default_value' => $config->get('api_rate_limit_per_ip') ?? 100,
      '#min' => 1,
    ];

    $form['api']['api_rate_limit_window'] = [
      '#type' => 'number',
      '#title' => $this->t('API rate limit window (seconds)'),
      '#description' => $this->t('Time window, in seconds, for the API rate limit.'),
      '#default_value' => $config->get('api_rate_limit_window') ?? 60,
      '#min' => 1,
    ];

    $form['api']['external_sync_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable external sync'),
      '#description' => $this->t('When enabled, each vote is queued for synchronization with an external API.'),
      '#default_value' => $config->get('external_sync_enabled') ?? FALSE,
    ];

    $form['api']['external_api_url'] = [
      '#type' => 'url',
      '#title' => $this->t('External API URL'),
      '#default_value' => $config->get('external_api_url') ?? '',
    ];

    $form['api']['external_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('External API key'),
      '#default_value' => $config->get('external_api_key') ?? '',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Handles form submission.
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('voting_core.settings')
      ->set('voting_enabled', $form_state->getValue('voting_enabled'))
      ->set('allow_anonymous_voting', $form_state->getValue('allow_anonymous_voting'))
      ->set('show_results_by_default', $form_state->getValue('show_results_by_default'))
      ->set('cache_results_ttl', $form_state->getValue('cache_results_ttl'))
      ->set('max_votes_per_hour', $form_state->getValue('max_votes_per_hour'))
      ->set('api_rate_limit_per_ip', $form_state->getValue('api_rate_limit_per_ip'))
      ->set('api_rate_limit_window', $form_state->getValue('api_rate_limit_window'))
      ->set('external_sync_enabled', $form_state->getValue('external_sync_enabled'))
      ->set('external_api_url', $form_state->getValue('external_api_url'))
      ->set('external_api_key', $form_state->getValue('external_api_key'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
