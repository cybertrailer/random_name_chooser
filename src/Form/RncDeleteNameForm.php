<?php

namespace Drupal\rnc\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;

/**
 * Confirm deletion of a single name entry.
 */
class RncDeleteNameForm extends ConfirmFormBase {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  protected $currentUser;

  /**
   * The entry ID to delete.
   *
   * @var int
   */
  protected $id;

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
    return 'rnc_delete_name_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete this name and reset matches?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('rnc.add_name');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $id = NULL) {
    $this->id = $id;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
	$uid = $this->currentUser->id();
    if ($this->id) {
      $this->database->delete('rnc_entries')
        ->condition('id', $this->id)
        ->execute();
		$this->database->delete('rnc_matches')
		  ->condition('uid', $uid)
		  ->execute();
		}
      $this->messenger()->addStatus($this->t('The name has been deleted.'));
		
    $form_state->setRedirect('rnc.add_name');
  }

}
