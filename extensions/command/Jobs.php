<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2011, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */
 
namespace li3_delayed_job\extensions\command;

use li3_delayed_job\models\Jobs as DelayedJobs;
use li3_delayed_job\models\Workers;

/**
 * Delayed_job (or DJ) encapsulates the common pattern of asynchronously
 * executing longer tasks in the background.
 */
class Jobs extends \lithium\console\Command {
  /**
   * Clear the delayed_job queue.
   */
  public function clear() {
    DelayedJobs::remove();
  }

  /**
   * Start a delayed_job worker.
   *
   * @param $quiet          bool
   * @param $group string
   * @param $minPriority   int
   * @param $maxPriority   int
   */
  public function work($quiet = false, $group = null, $minPriority = null, $maxPriority = null) {
    if (empty($quiet)) $quiet = false;
    if (empty($group)) $group = null;
    if ($minPriority !== null) $minPriority = intval($minPriority);
    if ($maxPriority !== null) $maxPriority = intval($maxPriority);
    $worker = new Workers(compact('quiet', 'group', 'minPriority', 'maxPriority'));
    $worker->start();
  }

  /**
   * Reschedule failed jobs
   */
  public function reschedule() {
    DelayedJobs::rescheduleFailedJobs();
  }
}