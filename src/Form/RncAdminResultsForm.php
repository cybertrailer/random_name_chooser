<?php

namespace Drupal\rnc\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form to view and reset generated matches for the current user's list.
 */
class RncAdminResultsForm extends FormBase {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Create array container.
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('database'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rnc_admin_results_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $uid = NULL) {

    if (empty($uid)) {
      $uid = $this->currentUser()->id();
    }

    // Confirmation step.
    if ($form_state->get('confirm_reset')) {
      $form['confirmation'] = [
        '#markup' => $this->t('<p>Are you sure you want to reset your matches? This will not delete the names in your list.</p>'),
      ];

      $form['confirm'] = [
        '#type' => 'submit',
        '#value' => $this->t('Yes, reset matches'),
        '#submit' => ['::confirmResetMatches'],
        '#button_type' => 'danger',
      ];

      $form['cancel'] = [
        '#type' => 'submit',
        '#value' => $this->t('Cancel'),
        '#submit' => ['::cancelReset'],
      ];

      return $form;
    }

    // Display matches table.
    $query = $this->database->select('rnc_matches', 'm');
    $query->leftJoin('rnc_entries', 's', 'm.selector_entry_id = s.id');
    $query->leftJoin('rnc_entries', 't', 'm.selected_entry_id = t.id');
    $query->fields('m', ['id']);
    $query->addField('s', 'name', 'selector_name');
    $query->addField('t', 'name', 'matched_name');
    $query->condition('m.uid', $uid);
    $results = $query->execute()->fetchAll();

    if (empty($results)) {
      $form['message'] = [
        '#markup' => $this->t('<div>No matches generated yet for your list.</div>'),
      ];
    }
    else {
      $header = [
        $this->t('Selector'),
        $this->t('Matched With'),
      ];

      $rows = [];
      foreach ($results as $r) {
        $rows[] = [
          $r->selector_name ?? '',
          $r->matched_name ?? '',
        ];
      }

      $form['matches'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No matches found.'),
      ];
    }

    // Reset matches button.
    $form['reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset Matches'),
      '#submit' => ['::askResetConfirmation'],
      '#button_type' => 'danger',
    ];

    // --- Add names button ---
    $form['actions']['add_names'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add Names'),
      '#submit' => ['::addNAmes'],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * Ask for confirmation before resetting matches.
   */
  public function askResetConfirmation(array &$form, FormStateInterface $form_state) {
    $form_state->set('confirm_reset', TRUE);
    $form_state->setRebuild();
  }

  /**
   * Trigger add names.
   */
  public function addNames(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('rnc.add_name');
  }

  /**
   * Perform the matches reset after confirmation.
   */
  public function confirmResetMatches(array &$form, FormStateInterface $form_state) {
    $uid = $this->currentUser()->id();

    // Delete only matches for this user; leave entries intact.
    $this->database->delete('rnc_matches')
      ->condition('uid', $uid)
      ->execute();

    $this->messenger()->addStatus($this->t('Your matches have been reset.'));
    $form_state->set('confirm_reset', FALSE);
    $form_state->setRebuild();
  }

  /**
   * Cancel the reset and return to matches display.
   */
  public function cancelReset(array &$form, FormStateInterface $form_state) {
    $form_state->set('confirm_reset', FALSE);
    $form_state->setRebuild();
    $this->messenger()->addStatus($this->t('Reset canceled.'));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Not used; submit handled by reset confirmation callbacks.
  }

}
