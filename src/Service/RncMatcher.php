<?php

namespace Drupal\rnc\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Set matches for name list.
 */
class RncMatcher {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Current user.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Get database and messneger into array.
   */
  public function __construct(Connection $database, MessengerInterface $messenger) {
    $this->database = $database;
    $this->messenger = $messenger;
  }

  /**
   * Generate all matches for the current entries.
   */
  public function generateMatches() {
    $entries = $this->database->select('rnc_entries', 'e')
      ->fields('e', ['id', 'name', 'spouse_letter'])
      ->execute()
      ->fetchAll();

    if (count($entries) < 3) {
      $this->messenger->addError('Not enough participants to generate matches.');
      return FALSE;
    }

    $max_attempts = 1000;

    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
      $shuffled = $entries;
      shuffle($shuffled);
      $valid = TRUE;
      $assignments = [];

      foreach ($entries as $selector) {
        $match = NULL;
        foreach ($shuffled as $candidate) {
          if ($candidate->id !== $selector->id && $candidate->spouse_letter !== $selector->spouse_letter) {
            $match = $candidate;
            break;
          }
        }

        if ($match === NULL) {
          $valid = FALSE;
          break;
        }

        $assignments[$selector->id] = $match;
        $shuffled = array_filter($shuffled, fn($c) => $c->id !== $match->id);
      }

      if ($valid) {
        foreach ($assignments as $selector_id => $match) {
          $this->database->insert('rnc_matches')
            ->fields([
              'selector_id' => $selector_id,
              'match_id' => $match->id,
              'selected_name' => $match->name,
              'created' => $this->time()->getRequestTime(),
            ])
            ->execute();
        }
        return TRUE;
      }
    }

    $this->messenger->addError('Could not generate a valid matching. Check entries for spouse conflicts.');
    return FALSE;
  }

  /**
   * Get the precomputed match for a selector.
   */
  public function getMatch($selector_id) {
    return $this->database->select('rnc_matches', 'm')
      ->fields('m', ['selected_name'])
      ->condition('selector_id', $selector_id)
      ->execute()
      ->fetchField();
  }

  /**
   * Check if matches already exist.
   */
  public function matchesExist() {
    $count = $this->database->select('rnc_matches', 'm')
      ->countQuery()
      ->execute()
      ->fetchField();

    return $count > 0;
  }

}
