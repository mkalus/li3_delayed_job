# Delayed JOb

Delayed_job (or DJ) encapsulates the common pattern of asynchronously executing longer tasks in the background.

This is a direct port of [Delayed::Job](https://github.com/tobi/delayed_job).

## Pre-Requisites

- Working installation of MongoDB

    http://www.mongodb.org/display/DOCS/Quickstart+Unix

- Working installation of Mongo PEAR extension

    `apt-get install php5-dev php-pear`

    `pecl install mongo`

    `echo 'extension=mongo.so' > /etc/php5/apache2/conf.d/mongo.ini`

    `service apache2 restart`

## Installation

Check out the code to your library directory

    cd libraries
    git clone git@github.com:cgarvis/li3_delayed_job.git
    
Include the library in your `/app/config/bootstrap/libraries.php`

    Libraries::add('li3_delayed_job');
    
## Usage

Jobs are simple objects with a method called perform.  Any object which responds to perform can be stuffed into the jobs collection. Job objects are serialized to yaml so that they can later be resurrected by the job runner.

## Creating a job

See examples under `./tests`.

## Running the jobs

You can invoke `li3 jobs work` which will start working off jobs.  You can cancel the task with `CTRL-C`

Keep in mind that each worker will check the database at least every 5 seconds.

### Cleaning up

You can invoke `li3 jobs clear` to delete all jobs in the queue

