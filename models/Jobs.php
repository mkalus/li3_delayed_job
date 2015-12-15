<?php

namespace li3_delayed_job\models;

use lithium\analysis\Logger;
use ErrorException;
use InvalidArgumentException;
use MongoDate;

class Jobs extends \lithium\data\Model {
  /**
   * The maxium number of attempts a job will be retried before it is considered completely failed.
   */
  const MAX_ATTEMPTS = 25;
  
  /**
   * The maxium length of time to let a job be locked out before it is retried.
   */
  const MAX_RUN_TIME = '4 hours';
  

  /**
   * Indexes
   * @var array
   */
  static public $_indexes = array(
    'run_at' =>  array(
      'keys' => array('run_at' => 1),
    ),
    'unique_key' =>  array(
      'keys' => array('unique_key' => 1),
    ),
    'priority' =>  array(
      'keys' => array('priority' => -1),
    ),
    'locked_at' =>  array(
      'keys' => array('locked_at' => 1),
    ),
    'locked_by' =>  array(
        'keys' => array('locked_by_host' => 1, 'locked_by_pid' => 1),
    ),
  );

  /**
   * Standard sorting
   * @var array
   */
  protected $_query = array(
    'order' => array('priority' => 'DESC'),
  );

  /**
   * @var bool
   */
  public $destoryFailedJobs = true;
  
  /**
   *
   */
  protected $entity;
  
  /**
   * @var int
   */
  public static $minPriority = null;
  
  /**
   * @var int
   */
  public static $maxPriority = null;
  
  /**
   * @var string
   */
  public $workerName;

  /**
   * @var string
   */
  public $workerHost;

  protected $_meta = array(
    'name' => null,
    'title' => null,
    'class' => null,
    'source' => null,
    'connection' => 'delayed_job',
    'initialized' => false
  );
  
  public function __construct() {
    $this->workerName = getmypid();
    $this->workerHost = gethostname();
  }
  
  public function __get($property) {
    if($property == 'name') {
      return $this->unique_key;
    }
    
    if($property == 'payload') {
      $this->payload = static::deserialize($this->handler);
      return $this->payload;
    }

    if(isset($this->entity)) {
      return $this->entity->$property;
    }
    
    throw new InvalidArgumentException("Property {$property} doesn't exist");
  }
  
  public function __set($property, $value) {
    if(isset($this->$property) || $property == 'name' || $property == 'payload') {
      $this->$property = $value;
    }

    $this->entity->$property = $value;
  }
  
  /**
   * When a worker is exiting, make sure we don't have any locked jobs.
   */
  public static function clearLocks() {
    // @TODO: implement
  }
  
  /**
   * Deserializes a string to an object.  If the 'perform' method doesn't exist, it throws an ErrorException
   *
   * @param $source string
   * @return object
   * @throws ErrorException
   */
  public static function deserialize($source) {
    $handler = unserialize(base64_decode($source));
    if(method_exists($handler, 'perform')) {
      return $handler;
    }
    
    throw new \ErrorException('Job failed to load: Unknown handler. Try to manually require the appropiate file.');
  }
  
  /**
   * Add a job to the queue
   *
   * @param $object object
   * @param $uniqueKey string unique key to not add double jobs
   * @param $priority int
   * @param $runAt MongoDate|string
   * @return bool
   * @throws ErrorException
   */
  public static function enqueue($object, $uniqueKey = null, $priority = 0, $runAt = null) {
    if(!method_exists($object, 'perform')) {
      throw new ErrorException('Cannot enqueue items which do not respond to perform');
    }
    
    if(!is_a($runAt, 'MongoDate')) {
      $runAt = new MongoDate($runAt);
    }

    $handler = base64_encode(serialize($object));
    if (empty($uniqueKey)) {
      $uniqueKey = md5($handler);
    }

    // do we have a job with a certain unique key already?
    $jobs = Jobs::count(array('conditions' => array('unique_key' => $uniqueKey)));
    if ($jobs > 0) return null; // job already exists

    $data = array(
      'attempts' => 0,
      'handler' => $handler,
      'priority' => $priority,
      'run_at' => $runAt,
      'completed_at' => null,
      'failed_at' => null,
      'locked_at' => null,
      'locked_by' => null,
      'unique_key' => $uniqueKey
    );

    $job = Jobs::create($data);
    return $job->save();
  }
  
  /**
   * Find and lock a job ready to be run
   *
   * @return bool|\lithium\data\entity\Document
   */
  public static function findAvailable($limit = 5, $maxRunTime = self::MAX_RUN_TIME) {
    $conditions = array(
      'run_at' => array('$lte' => new \MongoDate()),
      'locked_at' => null,
    );
    
    if(isset(static::$minPriority)) {
      $conditions['priority'] = array('$gte' => static::$minPriority);
    }
    
    if(isset(static::$maxPriority)) {
      $conditions['priority'] = array('$lt' => static::$maxPriority);
    }

    return Jobs::all(compact('conditions', 'limit'));
  }
  
  /**
   * @param \lithium\data\entity\Document
   * @param string                          Formatted for strtotime
   */
  protected function invoke() {
    $this->payload->perform();
    unset($this->payload);
  }
  
  protected function lockExclusively($maxRunTime, $worker) {
    $time_now = new MongoDate();

    if($this->locked_by != $worker) {
      $locked = Jobs::update(array('locked_at' => $time_now, 'locked_by' => $worker), array('_id' => $this->_id));
    } else {
      $locked = Jobs::update(array('locked_at' => $time_now), array('_id' => $this->_id), array('_id' => $this->_id));
    }    
    
    if($locked) {
      $this->locked_at = $time_now;
      $this->locked_by = $worker;
      return true;
    }
    
    return false;
  }
  
  /**
   * @param $message string
   */
  public function reschedule($message) {
    if($this->attempts < self::MAX_ATTEMPTS) {
      $this->attempts += 1;
      $this->run_at = $time;
      $this->last_error = $message;
      $this->unlock();
      $this->entity->save();
    } else {
      Logger::info('* [JOB] PERMANENTLY removing '.$this->name.' because of '.$this->attempts.' consequetive failures.');
      if($this->destoryFailedJobs) {
        Jobs::delete($this->entity);
      } else {
        $this->failed_at = new MongoDate();
      }
    }
  }
  
  /**
   * Run the next job we can get an exclusive lock on.
   * If no jobs are left we return -1
   *
   * @return int
   */
  public static function reserveAndRunOneJob($maxRunTime = self::MAX_RUN_TIME) {
    $jobs = static::findAvailable(5, $maxRunTime);
  
    foreach($jobs as $job) {
      $t = $job->runWithLock($maxRunTime);
      if(!is_null($t)) {
        return $t;
      }
    }
    
    return null;
  }
  
  public function runWithLock($entity, $maxRunTime, $workerName = null) {
    $workerName = $workerName ? $workerName : $this->workerName;  
    $this->entity = $entity;
    Logger::info('* [JOB] aquiring lock on '.$this->name);
    if(!$this->lockExclusively($maxRunTime, $workerName)) {
      Logger::warn('* [JOB] failed to aquire exclusive lock for '.$this->name);
      return null;
    }

    try {
      $time_start = microtime(true);
      $this->invoke();
      $this->delete($this->entity);
      $time_end = microtime(true);
      $runtime = $time_end - $time_start;
      
      Logger::info(sprintf('* [JOB] '.$this->name.' completed after %.4f', $runtime));
      return true;
    } catch(\Error $e) {
      $this->reschedule($e->getMessage());
      $this->logException($e);
      return false;
    }
  }
  
  /**
   * Unlock this job (note: not saved to DB)
   */
  public function unlock() {
    $this->locked_at = null;
    $this->locked_by = null;
  }
  
  /**
   * Do num jobs and return stats on success/failure.
   *
   * @param $num    int
   * @return array
   */
  public static function workOff($num = 100) {
    $success = $failure = 0;
    
    for($i = 0; $i < $num; $i++) {
      $result = self::reserveAndRunOneJob();
      if(is_null($result)) {
        break;
      }
      
      if($result === true) {
        $success++;
      } else {
        $failure++;
      }
    }
    
    return compact('success', 'failure');
  }
  
  public function logException($e) {
    print_r($e);
    Logger::error('* [JOB] ',$this->name.' failed with '.$e->message());
    Logger::error($e);
  }
}
