<?php

namespace Drupal\rnc\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;

/**
 * Form for adding names with unique passwords for Random Name Chooser.
 */
class RncAddNameForm extends FormBase {

  protected $database;
  protected $messenger;
  protected $currentUser;

  public function __construct(Connection $database, MessengerInterface $messenger, AccountProxyInterface $current_user) {
    $this->database = $database;
    $this->messenger = $messenger;
    $this->currentUser = $current_user;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('messenger'),
      $container->get('current_user')
    );
  }

  public function getFormId() {
    return 'rnc_add_name_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $uid = $this->currentUser->id();

    // Add CSS for styling.
    $form['#attached']['library'][] = 'rnc/rnc.styles';

    // Load user setting for spouse letter enable.
    $enable_spouse_letter = (bool) $this->database->select('rnc_user_settings', 's')
	  ->fields('s', ['enable_spouse_letter'])
	  ->condition('uid', $uid)
	  ->execute()
	  ->fetchField();

    // --- Confirmation step for reset ---
    if ($form_state->get('reset_confirm')) {
      $form['confirm_message'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' .
          $this->t('Are you sure you want to reset your entire list? This action cannot be undone.') .
          '</div>',
      ];

      $form['actions']['confirm'] = [
        '#type' => 'submit',
        '#value' => $this->t('Yes, delete my name list'),
        '#submit' => ['::resetListConfirmed'],
      ];

      $form['actions']['cancel'] = [
        '#type' => 'submit',
        '#value' => $this->t('Cancel'),
        '#submit' => ['::cancelReset'],
        '#limit_validation_errors' => [],
      ];

      return $form;
    }

    // --- Add new name ---
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#required' => TRUE,
	  '#maxlength' => 36,
    ];

    $form['password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password (visible to admin)'),
      '#required' => TRUE,
	  '#maxlength' => 36,
    ];

    if ($enable_spouse_letter) {
      $form['spouse_letter'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Spouse Letter'),
		'#description' => 'Enter the same letter when entering couple names so they will not picking each other',
        '#maxlength' => 1,
      ];
    }

    $form['notice'] = [
      '#type' => 'markup',
      '#markup' => '<p>Adding or deleting names once the match has begun will clear the matches.</p>',
    ];

    $form['actions']['add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add Name'),
      '#button_type' => 'primary',
    ];

    // --- Existing names list ---
    $header = [
      'name' => $this->t('Name'),
      'password' => $this->t('Password'),
      'spouse_letter' => $this->t('Spouse Letter'),
      'operations' => $this->t('Operations'),
    ];

    $rows = [];
    $entries = $this->database->select('rnc_entries', 'e')
      ->fields('e', ['id', 'name', 'spouse_letter', 'password'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAll();

    foreach ($entries as $entry) {
      $rows[] = [
        'data' => [
          'name' => $entry->name,
          'password' => $entry->password,
          'spouse_letter' => $entry->spouse_letter,
          'operations' => [
		    'data' => [
			  '#type' => 'link',
			  '#title' => $this->t('Delete'),
			  '#url' => Url::fromRoute('rnc.delete_name', ['id' => $entry->id]),
			  '#attributes' => ['class' => ['button', 'button--danger']],
			],
		  ],
        ],
      ];
    }

    $form['entries'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No names have been added yet.'),
    ];

    // --- Reset list button ---
    $form['actions']['reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset List'),
      '#submit' => ['::resetList'],
      '#limit_validation_errors' => [],
    ];

    // --- View matches button ---
    $form['actions']['view_match'] = [
      '#type' => 'submit',
      '#value' => $this->t('View Match'),
      '#submit' => ['::viewMatch'],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uid = $this->currentUser->id();

    $this->database->insert('rnc_entries')
      ->fields([
        'uid' => $uid,
        'name' => $form_state->getValue('name'),
        'password' => $form_state->getValue('password'),
        'spouse_letter' => $form_state->getValue('spouse_letter'),
      ])
      ->execute();

    $this->database->delete('rnc_matches')
      ->condition('uid', $uid)
      ->execute();

    $this->messenger->addStatus($this->t('Name added: @name', ['@name' => $form_state->getValue('name')]));
    $form_state->setRebuild(TRUE);
  }

  /**
   * Trigger reset confirmation.
   */
  public function resetList(array &$form, FormStateInterface $form_state) {
    $form_state->set('reset_confirm', TRUE);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Trigger view match.
   */
  public function viewMatch(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('rnc.results');
  }

  /**
   * Execute reset after confirmation.
   */
  public function resetListConfirmed(array &$form, FormStateInterface $form_state) {
    $uid = $this->currentUser->id();

    // Delete all names for this user.
    $this->database->delete('rnc_entries')
      ->condition('uid', $uid)
      ->execute();

    // Also clear matches for safety.
    $this->database->delete('rnc_matches')
      ->condition('uid', $uid)
      ->execute();

    $this->messenger->addWarning($this->t('Your name list has been cleared.'));
    $form_state->setRedirect('rnc.add_name');
  }

  /**
   * Cancel reset.
   */
  public function cancelReset(array &$form, FormStateInterface $form_state) {
    $this->messenger->addStatus($this->t('Reset cancelled.'));
    $form_state->setRedirect('rnc.add_name');
  }

}
