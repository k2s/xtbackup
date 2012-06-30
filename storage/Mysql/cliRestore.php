<?php
define('GETOPT_NOTSWITCH',0); // Internal use only
define('GETOPT_SWITCH',1);
define('GETOPT_ACCUMULATE',2);
define('GETOPT_VAL',3);
define('GETOPT_MULTIVAL',4);
define('GETOPT_KEYVAL',5);

// parse command line
try {
    $options = array(
        'user' => array('switch' => array('u', 'user'), 'type' => GETOPT_VAL, 'default' => 'root', 'help' => 'mysql user name under which we will perform restore'),
        'password' => array('switch' => array('p', 'password'), 'type' => GETOPT_VAL, 'default' => (string) '', 'help' => 'mysql user password'),
        'host' => array('switch' => array('h', 'host'),'type' => GETOPT_VAL, 'default' => 'localhost', 'help'=>'mysql server host name'),
        'port' => array('switch' => array('P', 'port'), 'type' => GETOPT_VAL, 'default'=>3306, 'help' => 'mysql server port number'),
//        'directory' => array('switch' => array('d', 'directory'), 'type' => GETOPT_VAL, 'help' => 'directory with backup data'),
        'database' => array('switch' => array('D', 'database'), 'type' => GETOPT_VAL, 'help' => 'target database name'),
        'drop-db' => array('switch' => array('drop-db'), 'type' => GETOPT_SWITCH, 'help' => 'will drop DB if exists'),
        'quite' => array('switch' => array('q', 'quite'), 'type' => GETOPT_SWITCH, 'help' => 'will not prompt user to approve restore'),
        'help' => array('switch' => array('h', 'help'), 'type' => GETOPT_SWITCH, 'help' => 'display instruction how to use cli.php'),
/*        'action' => array('switch' => array('a', 'action'), 'type' => GETOPT_VAL, 'help' => 'what action to run'),
        'params' => array('switch' => 'p', 'type' => GETOPT_KEYVAL, 'help' => 'set request parameters'),
        'cronList' => array('switch' => array('l', 'cron-list'), 'type' => GETOPT_SWITCH, 'help' => 'list all actions marked for scheduling'),
        'verbose' => array('switch' => array('v', 'verbose'), 'type' => GETOPT_SWITCH, 'help' => 'enable output of PHP errors, this option overides php.ini and application.ini settings - Important to enable if you running code that failing in cli and you getting no errors'),
        'help' => array('switch' => array('h', 'help'), 'type' => GETOPT_SWITCH, 'help' => 'display instruction how to use cli.php'),
        'apache' => array('switch' => array('A', 'env-from-apache'), 'type' => GETOPT_MULTIVAL, 'help' =>'parse apache virtual host configuration file (may be used several times)'),
        'info' => array('switch' => array('i', 'info'), 'type' => GETOPT_SWITCH, 'help' => 'bootstrap application, show loaded configuration and exit without running the application'),*/
    );
    $opts = getopts($options, $_SERVER['argv']);
} catch (Exception $e) {
    help($options);
    echo $e->getMessage() . "\n\n";
    exit;
}

if ($opts['help']) {
    // show help message and exit
    help($options);
    exit;
}

$folder = "";
if (count($opts['cmdline'])!=1) {
    help($options, "ERROR: you have to specify backup directory");
} else {
    $folder = $opts['cmdline'][0];
}

/*
if (!$opts['directory']) {
    help($options, "ERROR: you have to specify backup directory (-d or --directory)");
}
*/

$originalDbName = file_get_contents($folder . '/db/_name');
if (!$opts['database']) {
    $opts['database'] = $originalDbName;
}

/** connect to DB **/
$db = new PDO(
    "mysql:host=localhost;dbname=mysql",
    $opts['user'],
    $opts['password'],
    array(
         PDO::MYSQL_ATTR_LOCAL_INFILE=>1,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
    )
);
// let PDO throw exception on errors
//$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/** show info */

/** prompt if to continue **/
if (!$opts['quite']) {
    echo "do you want to start restore (y<enter>) ?";
    $fp = fopen('php://stdin', 'r');
    $answr = trim(fgets($fp, 1024));
    fclose($fp);
    if (strtolower($answr)=="y") {
        echo "\n";
        exit;
    }
}

/** restore process **/

// drop database if requested
if ($opts['drop-db']) {
    $db->exec("DROP DATABASE IF EXISTS `$opts[database]`");
}

// create database
$sql = file_get_contents($folder . '/db/_create');
$sql = str_replace("`$originalDbName`", "`$opts[database]`", $sql);
try {
    $db->exec($sql);
} catch (PDOException $e) {
    if ($e->getCode()=="HY000") {
        echo $e->getMessage()."\n";
        exit(1);
    }
    throw $e;
}

// change to DB
$db->query("use `$opts[database]`");

// prepare import
$sql = <<<SQL
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
SQL;
$db->exec($sql);

// create users
execSqlFromFolder($folder."/users/");

// create functions
execSqlFromFolder($folder."/functions/");

// create tables
execSqlFromFolder($folder."/tables/");

// import data
// TODO detect if mysql server is on localhost
importDataFromFolderToLocalServer($folder."/data/");

// create index
execSqlFromFolder($folder."/indexes/");

// create references
execSqlFromFolder($folder."/refs/");

// create views
execSqlFromFolder($folder."/views/");

// create procedures
execSqlFromFolder($folder."/procedures/");

// create triggers
execSqlFromFolder($folder."/triggers/");

// create grants
execSqlFromFolder($folder."/grants/");

// finish import
$sql = <<<SQL

SQL;
//$db->exec($sql);



//////////////////////////////////////
function importDataFromFolderToRemoteServer($path, $truncate=true)
{
    die("not supported yet");
}
function importDataFromFolderToLocalServer($path, $truncate=true)
{
    global $db;

    if (file_exists($path) && false!==($handle = opendir($path))) {
        while (false !== ($fn = readdir($handle))) {
            if ($fn!="." && $fn!="..") {
                echo "import data to table '$fn'\n";
                if ($truncate) {
                    $db->exec("TRUNCATE TABLE `$fn`");
                }

                $db->exec("LOAD DATA INFILE '$path$fn' INTO TABLE `$fn` CHARACTER SET UTF8;");
            }
        }
        closedir($handle);
    }
}

function execSqlFromFolder($path)
{
    global $db;
    if (file_exists($path) && false!==($handle = opendir($path))) {
        while (false !== ($fn = readdir($handle))) {
            if ($fn!="." && $fn!="..") {
                $sql = file_get_contents($path.$fn);
                $db->exec($sql);
            }
        }
        closedir($handle);
    }
}

function help($opts, $message=false)
{
    $cmdName = basename(__FILE__);
    echo "Run with interpreter: php -f {$cmdName}.php -- [<parameters>] [<backup folder>]\n";
    echo "Example: php -f cli.php -- -D newdb /tmp/backup\n";

    foreach ($opts as $flag => $opt) {
//        var_dump($opt);die();
        if (isset($opt['switch'][1]) && isset($opt['switch'][0])) {
            echo '-' . $opt['switch'][0] . ', --' . $opt['switch'][1] . (isset($opt['help']) ? "\t" . $opt['help'] : '') . "\n";
        } elseif (isset($opt['switch'][0])) {
            echo '-' . $opt['switch'][0] . (isset($opt['help']) ? "\t\t" . $opt['help'] : '') . "\n";
        } else {
            continue;
        }
    }

    if (false!==$message) {
        echo "\n\n$message\n";
    }
}


	/**********************************************************************************
	* Coded by Matt Carter (M@ttCarter.net)                                           *
	***********************************************************************************
	* getOpts                                                                       *
	* Extended CLI mode option and switch handling                                    *
	*                                                                                 *
	**********************************************************************************/
/*
GetOpt Readme
+++++++++++++++

getOpt is a library to load commandline options in replacement for the horribly inflexible 'getopts' native php function. It can be invoked using the typical 'require', 'include' (or their varients) from any PHP scripts.

LEGAL STUFF
===========
This code is covered under the GPL with republishing permissions provided credit is given to the original author Matt Carter (M@ttCarter.com).

LATEST VERSIONS
===============
The latest version can be found on the McStuff website currently loadted at http://ttcarter.com/mcstuff or by contacting the author at M@ttCarter.com (yes thats an email address).

QUICK EXAMPLE
=============
#!/usr/bin/php -qC
<?php
require('getopts.php');
$opts = getopts(array(
	'a' => array('switch' => 'a','type' => GETOPT_SWITCH),
	'b' => array('switch' => array('b','letterb'),'type' => GETOPT_SWITCH),
	'c' => array('switch' => 'c', 'type' => GETOPT_VAL, 'default' => 'defaultval'),
	'd' => array('switch' => 'd', 'type' => GETOPT_KEYVAL),
	'e' => array('switch' => 'e', 'type' => GETOPT_ACCUMULATE),
	'f' => array('switch' => 'f'),
),$_SERVER['argv']);
?>

When used with the commandline:
>./PROGRAM.php -ab -c 15 -d key=val -e 1 --letterb -d key2=val2 -eeeeeee 2 3

Gives the $opt variable the following structure:
$opt = Array (
	[cmdline] => Array (
		[0] => 1
		[1] => 2
		[2] => 3
	)
	[a] => 1
	[b] => 1
	[c] => 15
	[d] => Array (
		[key] => val
		[key2] => val2
	)
	[e] => 8
	[f] => 0
)

Of course the above is a complex example showing off most of getopts functions all in one.

Types and there meanings
========================

GETOPT_SWITCH
	This is either 0 or 1. No matter how many times it is specified on the command line.

	>PROGRAM -c -c -c -cccc
	Gives:
	$opt['c'] = 1;

	>PROGRAM
	Gives:
	$opt['c'] = 0

GETOPT_ACCUMULATE
	Each time this switch is used its value increases.

	>PROGRAM -vvvv
	Gives:
	$opt['v'] = 4

GETOPT_VAL
	This expects a value after its specification.

	>PROGRAM -c 32
	Gives:
	$opt['c'] = 32

	Multiple times override each precursive invokation so:

	>PROGRAM -c 32 -c 10 -c 67
	Gives:
	$opt['c'] = 67

GETOPT_MULTIVAL
	The same format as GETOPT_VAL only this allows multiple values. All incomming variables are automatically formatted in an array no matter how few items are present.

	>PROGRAM -c 1 -c 2 -c 3
	Gives:
	$opt['c'] = array(1,2,3)

	>PROGRAM -c 1
	Gives:
	$opt['c'] = array(1)

	>PROGRAM
	Gives:
	$opt['c'] = array()

GETOPT_KEYVAL
	Allows for key=value specifications.

	>PROGRAM -c key=val -c key2=val2 -c key3=val3 -c key3=val4
	Gives:
	$opt['c'] = array('key1' => 'val2','key2' => 'val2','key3' => array('val3','val4');

*/

/**
* @param array $options The getOpts specification. See the documentation for more details
* @param string|array $fromarr Either a command line of switches or the array structure to take options from. If omitted $_SERVER['argv'] is used
* @return array Processed array of return values
*/
function getopts($options,$fromarr = null) {
	if ($fromarr === null)
		$fromarr = $_SERVER['argv'];
	elseif (!is_array($fromarr))
		$fromarr = explode(' ',$fromarr); // Split it into an array if someone passes anything other than an array
	$opts = array('cmdline' => array()); // Output options
	$optionslookup = array(); // Reverse lookup table mapping each possible option to its real $options key
	foreach ($options as $optitem => $props) { // Default all options
		if (!isset($props['type'])) { // User didnt specify type...
				$options[$optitem]['type'] = GETOPT_SWITCH; // Default to switch
				$props['type'] = GETOPT_SWITCH; // And again because we're not using pointers here
		}
		switch ($props['type']) {
				case GETOPT_VAL:
					if (isset($props['default'])) {
						$opts[$optitem] = $props['default'];
						break;
					} // else fallthough...
				case GETOPT_ACCUMULATE:
				case GETOPT_SWITCH:
					$opts[$optitem] = 0;
					break;
				case GETOPT_MULTIVAL:
				case GETOPT_KEYVAL:
					$opts[$optitem] = array();
		}
		if (is_array($props['switch'])) { // Create the $optionslookup var from an array of aliases
				foreach ($props['switch'] as $switchalias)
					$optionslookup[$switchalias] = $optitem;
		} else { // Create the $optionslookup ref as a simple pointer to the hash
			$optionslookup[$props['switch']] = $optitem;
		}
	}

	$inswitch = GETOPT_NOTSWITCH;
	for ($i = 1; $i < count($fromarr); $i++) {
		switch ($inswitch) {
			case GETOPT_MULTIVAL:
			case GETOPT_VAL:
				if (substr($fromarr[$i],0,1) == '-') // Throw error if the user tries to simply set another switch while the last one is still 'open'
					throw new Exception("The option '{$fromarr[$i]}' needs a value.\n");
				GETOPT_setval($opts,$options,$inswitch_key,$fromarr[$i]);
				$inswitch = GETOPT_NOTSWITCH; // Reset the reader to carry on reading normal stuff
				break;
			case GETOPT_KEYVAL: // Yes, the awkward one.
				if (substr($fromarr[$i],0,1) == '-') // Throw error if the user tries to simply set another switch while the last one is still 'open'
					throw new Exception("The option '{$fromarr[$i]}' needs a value.\n");
				$fromarr[$i] = strtr($fromarr[$i],':','='); // Replace all ':' with '=' (keeping things simple and fast.
				if (strpos($fromarr[$i],'=') === false)
					throw new Exception("The option '$inswitch_userkey' needs a key-value pair. E.g. '-$inswitch_userkey option=value'");
				GETOPT_setval($opts,$options,$inswitch_key,explode('=',$fromarr[$i]));
				$inswitch = GETOPT_NOTSWITCH; // Reset the reader to carry on reading normal stuff
				break;
			case GETOPT_NOTSWITCH: // General invokation of no previously complex cmdline options (i.e. i have no idea what to expect next)
				if (substr($fromarr[$i],0,1) == '-') {
					// Probably the start of a switch
					if ((strlen($fromarr[$i]) == 2) || (substr($fromarr[$i],0,2) == '--')) { // Single switch OR long opt (might be a weird thing like VAL, MULTIVAL etc.)
							$userkey = ltrim($fromarr[$i],'-');
							if (!isset($optionslookup[$userkey]))
									throw new Exception("Unknown option '-$userkey'\n");
								$hashkey = $optionslookup[$userkey]; // Replace with the REAL key
							if (($options[$hashkey]['type'] == GETOPT_SWITCH) || ($options[$hashkey]['type'] == GETOPT_ACCUMULATE)) {
								GETOPT_setval($opts,$options,$hashkey,1); // Simple enough - Single option specified in switch that needs no params.
							} else { // OK the option needs a value. This is where the fun begins
								$inswitch = $options[$hashkey]['type']; // Set so the next process cycle will pick it up
								$inswitch_key = $hashkey;
								$inswitch_userkey = $userkey;
							}
					} else {
						// Multiple letters. Probably a bundling
						for ($o = 1; $o < strlen($fromarr[$i]); $o++) {
							$hashkey = substr($fromarr[$i],$o,1);
							if (!isset($optionslookup[$hashkey]))
									throw new Exception("Unknown option '-$hashkey'\n");
							if (($options[$optionslookup[$hashkey]]['type'] != GETOPT_SWITCH) && ($options[$optionslookup[$hashkey]]['type'] != GETOPT_ACCUMULATE))
								throw new Exception("Option '-$hashkey' requires a value.\n");
							GETOPT_setval($opts,$options,$optionslookup[$hashkey],1);
						}
					}
				} else {
					$opts['cmdline'][] = $fromarr[$i]; // Just detritus on the cmdline
				}
				break;
		}
	}
	return $opts;
}

function GETOPT_setval(&$opts,&$options,$key,$value) {
	switch ($options[$key]['type']) {
		case GETOPT_VAL:
		case GETOPT_SWITCH:
			$opts[$key] = $value;
				break;
		case GETOPT_ACCUMULATE:
			$opts[$key]++;
				break;
		case GETOPT_MULTIVAL:
			$opts[$key][] = $value;
			break;
		case GETOPT_KEYVAL:
			$opts[$key][$value[0]] = $value[1];
	}
}