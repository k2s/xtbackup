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

// show help message
$helpIdx = array_search("--help", $argv);
if (false!==$helpIdx) {
    echo "TODO some help text for this command line application\n";
    exit(Core_StopException::RETCODE_OK);
}

// we need quite output if initializing INI file from start of the engine
$helpIdx = array_search("--init", $argv);
if (false!==$helpIdx) {
    $output = false;
} else {
    // default output will be used in start phase of engine
    $output = null;
}

// initialize backup engine
$engine = new Core_Engine($argv, $output);

// show help message and stop if requested
if (false!==$helpIdx) {
    echo $engine->generateIni();
    exit(Core_StopException::RETCODE_OK);
}

$engine->setAppHelpMessage("Use command line parameter --help to see usage instructions.");
$engine->init();
$engine->run();
exit($engine->finish());