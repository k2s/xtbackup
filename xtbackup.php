<?php
/**
 * Main program
 *
 * Loads the backup engine and passes command line arguments to it.
 * Then it runs all required steps to execute backup task.
 * 
 * 
 */

// make sure we will find include files
set_include_path(realpath(dirname(__FILE__)));

// don't search for ~.aws/sdk/config.inc.php which causes open_basedir problem
define('AWS_DISABLE_CONFIG_AUTO_DISCOVERY', true);

// make some more adjustments to PHP configuration
ini_set('display_startup_errors', true);
ini_set('display_errors', true);
ini_set('html_errors', false);
ini_set('log_errors', false);
ini_set('ignore_repeated_errors', false);
ini_set('ignore_repeated_source', false);
ini_set('report_memleaks', true);
ini_set('track_errors', true);
ini_set('docref_root', 0);
ini_set('docref_ext', 0);
error_reporting(E_ALL | E_STRICT);
set_time_limit(0);

// load engine core
require_once 'core/Engine.php';

// show help message
$helpIdx = array_search("--help", $argv);
if (false!==$helpIdx) {
    echo "Setup INI files as explained here http://k2s.github.io/xtbackup/index.html\n";
    echo "then to run it use 'php -f xtbackup.php -- ini[]=myconfig.ini'\n";
    echo "You can combine multiple INI files like this: 'php -f xtbackup.php -- ini[]=myconfig.ini ini[]=accesscredentials.ini'\n\n";
    echo "You can override INI setting by adding parameter to the end of command for example: \n";
		echo "'php -f xtbackup.php -- ini[]=myconfig.ini ini[]=accesscredentials.ini storage.s3.update=true'\n";
    echo "overrides 'storage.s3.update' that is setup within INI files.\n";
    exit(Core_StopException::RETCODE_OK);
}

// it is possible to suppress output to console in start phase of engine
$quiteStartIdx = array_search("--quiet-start", $argv);
if (false!==$quiteStartIdx) {
    unset($argv[$quiteStartIdx]);
}

// we need quite output if initializing INI file from start of the engine
$initIdx = array_search("--init", $argv);
if (false!==$initIdx || false!==$quiteStartIdx) {
    $output = false;
} else {
    // default output will be used in start phase of engine
    $output = null;
}

// initialize backup engine
$engine = new Core_Engine($argv, $output);

// show help message and stop if requested
if (false!==$initIdx) {
    echo $engine->generateIni();
    exit(Core_StopException::RETCODE_OK);
}

$engine->setAppHelpMessage("Use command line parameter --help to see usage instructions.");
$engine->init();
$engine->run();
exit($engine->finish());
