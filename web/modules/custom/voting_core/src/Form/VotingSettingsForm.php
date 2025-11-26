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
   * Gets the names of the editable configuration objects.
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['voting_core.settings'];
  }

  /**
   * Gets the form ID.
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'voting_core_settings';
  }

  /**
   * Builds the voting settings form.
   * {@inheritdoc}
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

    return parent::buildForm($form, $form_state);
  }

  /**
   * Handles form submission.
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('voting_core.settings')
      ->set('voting_enabled', $form_state->getValue('voting_enabled'))
      ->set('allow_anonymous_voting', $form_state->getValue('allow_anonymous_voting'))
      ->set('show_results_by_default', $form_state->getValue('show_results_by_default'))
      ->set('cache_results_ttl', $form_state->getValue('cache_results_ttl'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
