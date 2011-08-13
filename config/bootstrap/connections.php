<?php

use lithium\data\Connections;

/**
 * use MongoDB as your database.
 */
Connections::add('delayed_job', array(
	'type' => 'MongoDb',
	'host' => 'localhost',
	'database' => 'revere_sms'
));