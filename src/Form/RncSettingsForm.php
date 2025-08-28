<?php

namespace Drupal\rnc\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Url;
use Drupal\Core\Link;

/**
 * Settings form for RNC (per-user).
 */
class RncSettingsForm extends FormBase {

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  public function __construct(Connection $database, AccountProxyInterface $current_user) {
    $this->database = $database;
    $this->currentUser = $current_user;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rnc_user_settings_form';
  }

  /**
   * Load current user’s settings from DB.
   */
  protected function loadSettings() {
    $uid = $this->currentUser->id();
    return $this->database->select('rnc_user_settings', 's')
      ->fields('s')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc() ?: [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $uid = $this->currentUser()->id();
	$settings = $this->loadSettings();
	
    $form['max_entries'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum entries'),
      '#default_value' => $settings['max_entries'] ?? 20,
      '#min' => 1,
    ];

    $form['enable_spouse_letter'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable spouse letter'),
      '#default_value' => $settings['enable_spouse_letter'] ?? 0,
    ];

    $form['instructions'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Instructions for participants'),
      '#format' => 'full_html',
      '#default_value' => $settings['instructions'] ?? '',
    ];

    // Add the link after the form elements.
    $absolute_url = Url::fromUri('internal:/random-name-chooser/' . $uid, ['absolute' => TRUE])->toString();
    $form['rnc_link'] = [
      '#type' => 'markup',
      '#markup' => 'Link to send to participants:<br />' . $absolute_url,
      '#weight' => 100,
    ];


    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save settings'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uid = $this->currentUser->id();

    // Upsert into rnc_user_settings.
    $this->database->merge('rnc_user_settings')
      ->key(['uid' => $uid])
      ->fields([
        'max_entries' => $form_state->getValue('max_entries'),
        'enable_spouse_letter' => $form_state->getValue('enable_spouse_letter'),
        'instructions' => $form_state->getValue('instructions')['value'],
      ])
      ->execute();

    $this->messenger()->addStatus($this->t('Your settings have been saved.'));
  }

}
