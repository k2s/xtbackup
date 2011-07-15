<?php
/**
 * Main program
 *
 * Loads the backup engine and passes command line arguments to it.
 * Then it runs all required steps to execute backup task.
 */

// make sure we will find include files
set_include_path(realpath(dirname(__FILE__)));

require_once 'core/Engine.php';

$engine = new Core_Engine($argv);
$engine->init();
$engine->run();
$engine->finish();