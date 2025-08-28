<?php

namespace Drupal\rnc\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for participants to reveal or generate their match (per-user list).
 */
class RncSelectNameForm extends FormBase {

  protected $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  public static function create(ContainerInterface $container) {
    return new static($container->get('database'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rnc_select_name_form';
  }

  /**
   * Build the selection form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $uid = NULL) {

  if (empty($uid)) {
    $uid = \Drupal::currentUser()->id();
  }

  $form['uid'] = [
    '#type' => 'hidden',
    '#value' => $uid,
  ];
 
    // Load user setting.
    $settings = $this->database->select('rnc_user_settings', 's')
      ->fields('s', ['instructions'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc() ?: ['instructions' => ''];

    if (!empty($settings['instructions'])) {
      $form['instructions'] = ['#markup' => '<p>' . $settings['instructions'] . '</p>'];
    }

	$connection = \Drupal::database();

    // Load participants for this user only.
    $result = $connection->select('rnc_entries', 'e')
      ->fields('e', ['id', 'name'])
      ->condition('e.uid', $uid)
      ->orderBy('e.name', 'ASC')
      ->execute()
      ->fetchAll();

    if (empty($result)) {
      $form['message'] = [
        '#markup' => $this->t('No participants found in your list. Please add names first.'),
      ];
      return $form;
    }

    $options = [];
    foreach ($result as $r) {
      $options[$r->id] = $r->name;
    }

    $form['selector_entry_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Select your name'),
      '#options' => $options,
      '#required' => TRUE,
    ];

    $form['password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
      '#required' => TRUE,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reveal Match'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Validate password belongs to selected participant for this user.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
	$uid = $form_state->getValue('uid');
    $selector_id = (int) $form_state->getValue('selector_entry_id');
    $password = (string) $form_state->getValue('password');

    $stored = \Drupal::database()->select('rnc_entries', 'e')
      ->fields('e', ['password'])
      ->condition('e.id', $selector_id)
      ->condition('e.uid', $uid)
      ->execute()
      ->fetchField();

    if ($stored === FALSE || $stored !== $password) {
      $form_state->setErrorByName('password', $this->t('Invalid password for the selected name.'));
    }
  }

  /**
   * Submit: generate (if needed) and reveal match.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
	$uid = $form_state->getValue('uid');
    $selector_id = (int) $form_state->getValue('selector_entry_id');
    $connection = \Drupal::database();

    // Load participant (scoped to user).
    $participant = $connection->select('rnc_entries', 'e')
      ->fields('e', ['id', 'name'])
      ->condition('e.id', $selector_id)
      ->condition('e.uid', $uid)
      ->execute()
      ->fetchObject();

    if (!$participant) {
      $this->messenger()->addError($this->t('Participant not found.'));
      return;
    }

    // Do we already have matches for this user?
    $matches_exist = (int) $connection->select('rnc_matches', 'm')
      ->condition('m.uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();

    if (!$matches_exist) {
      try {
        $this->generateMatches($uid);
      }
      catch (\Exception $e) {
        // Bubble a friendly message; real exception logged by Drupal if needed.
        $this->messenger()->addError($this->t('Unable to generate matches. Please adjust your list or spouse letters.'));
        return;
      }
    }

    // Correct usage: call leftJoin on the query object, then call fields() on the query.
    $q = $connection->select('rnc_matches', 'm');
    $q->leftJoin('rnc_entries', 'e', 'm.selected_entry_id = e.id'); // modify $q, returns alias string
    $q->fields('e', ['name']); // safe: fields() invoked on $q, using alias 'e'
    $q->condition('m.selector_entry_id', $selector_id);
    $q->condition('m.uid', $uid);
    $match_name = $q->execute()->fetchField();

    if ($match_name) {
      $this->messenger()->addStatus($this->t('<h2>@selector matched with @match</h2>', [
        '@selector' => $participant->name,
        '@match' => $match_name,
      ]));
    }
    else {
      $this->messenger()->addError($this->t('No match found.'));
    }
  }

  /**
   * Iterative match generation constrained to current user's list.
   *
   * @param int $uid
   *   The user id whose list to generate for.
   *
   * @throws \Exception
   */
  protected function generateMatches($uid) {
    $connection = \Drupal::database();

    $entries = $connection->select('rnc_entries', 'e')
      ->fields('e', ['id', 'name', 'spouse_letter'])
      ->condition('e.uid', $uid)
      ->execute()
      ->fetchAll();

    if (count($entries) < 2) {
      throw new \Exception('Not enough participants to generate matches.');
    }

    // Build id list and spouse lookup.
    $ids = [];
    $spouse_by_id = [];
    foreach ($entries as $e) {
      $ids[] = $e->id;
      $spouse_by_id[$e->id] = $e->spouse_letter ?? '';
    }

    $attempts = 0;
    $max_attempts = 1000;

    do {
      $attempts++;
      $targets = $ids;
      shuffle($targets);

      $valid = TRUE;
      $pairs = [];

      for ($i = 0; $i < count($ids); $i++) {
        $sel = $ids[$i];
        $got = $targets[$i];

        // No self; no same non-empty spouse_letter.
        if ($sel === $got) { $valid = FALSE; break; }
        $s1 = $spouse_by_id[$sel];
        $s2 = $spouse_by_id[$got];
        if ($s1 !== '' && $s1 === $s2) { $valid = FALSE; break; }

        $pairs[] = [
          'uid' => $uid,
          'selector_entry_id' => $sel,
          'selected_entry_id' => $got,
        ];
      }

      if ($valid) {
        // Clear existing matches for this user and insert new pairs.
        $connection->delete('rnc_matches')->condition('uid', $uid)->execute();
        foreach ($pairs as $p) {
          $connection->insert('rnc_matches')->fields($p)->execute();
        }
        return;
      }
    } while ($attempts <= $max_attempts);

    throw new \Exception('Could not generate a valid matching after many attempts.');
  }

}
