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
        'no-data' => array('switch' => array('no-data'), 'type' => GETOPT_SWITCH, 'help' => 'skip data import'),
        'decompress-only' => array('switch' => array('do', 'decompress-only'), 'type' => GETOPT_SWITCH, 'help' => 'decompress data files only'),
        'decompress-folder' => array('switch' => array('df', 'decompress-folder'), 'type' => GETOPT_VAL, 'help' => 'if data have to be uncompressed it will happen into data folder, you may change this with this option'),
        'decompress-action' => array('switch' => array('da', 'decompress-action'), 'type' => GETOPT_VAL, 'default'=>'delete', 'help' => <<<TXT
if data had to be decompressed on import this will happen after import completes:
\tdelete - delete decompressed
\tkeep - keep decompressed and compressed
\treplace - keep decompressed and delete compressed
TXT
        ),
        'force' => array('switch' => array('f', 'force'), 'type' => GETOPT_SWITCH, 'help' => 'will not prompt user to approve restore'),
        'quite' => array('switch' => array('q', 'quite'), 'type' => GETOPT_SWITCH, 'help' => 'will not print messages'),
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

// object with main restore logic
$restore = new RestoreMysql($folder, $opts);
echo $restore->getInfo();

if ($opts['decompress-only']) {
    $restore->decompressOnly();
    // end with return code
    die($restore->getReturnCode());
}

/** prompt if to continue **/
if (!$opts['force']) {
    echo "do you want to start restore (y<enter>) ?";
    $fp = fopen('php://stdin', 'r');
    $answer = trim(fgets($fp, 1024));
    fclose($fp);
    if (strtolower($answer)=="y") {
        echo "\n";
        // return code to recognize user cancellation
        die(RestoreMysql::RETCODE_USER_CANCEL);
    }
}

$restore->restore();

// end with return code
die($restore->getReturnCode());


class RestoreMysql
{
    const RETCODE_OK = 0;
    const RETCODE_USER_CANCEL = 1;

    /**
     * @var array
     */
    protected $_opts;
    /**
     * @var string
     */
    protected $_backupFolder;
    /**
     * @var \Log
     */
    protected $_log;
    /**
     * @var \PDO
     */
    protected $_db;
    /**
     * @var string
     */
    protected $_originalDbName;

    const VALIDATE_RESTORE = 0;
    const VALIDATE_DECOMPRESS = 1;

    public function __construct($backupFolder, $opts)
    {
        $this->_opts = $opts;

        $this->_backupFolder = $backupFolder;

        $this->_originalDbName = file_get_contents($this->_backupFolder . '/db/_name');
        if (!$this->_opts['database']) {
            $this->_opts['database'] = $this->_originalDbName;
        }

        // prepare timer
        $this->_log = new Log(!$opts['quite']);
    }

    protected function _fixFolderName($folder)
    {
        return rtrim($folder, "/\\").DIRECTORY_SEPARATOR;
    }
    
    public function validateOpts($opts, $action=self::VALIDATE_RESTORE)
    {
        $this->_backupFolder = $this->_fixFolderName($this->_backupFolder);
        if (!$this->_opts['decompress-folder']) {
            $this->_opts['decompress-folder'] = $this->_backupFolder.'data'.DIRECTORY_SEPARATOR;
        } else {
            $this->_opts['decompress-folder'] = $this->_fixFolderName($this->_opts['decompress-folder']);
        }

        switch ($this->_opts['decompress-action']) {
            case 'delete':
            case 'keep':
            case 'replace':
                break;
            default:
                throw new Exception("Unknown decompress action '".$this->_opts['decompress-action']."'.");
        }

        if ($action==self::VALIDATE_DECOMPRESS) {
            return;
        }
    }

    public function getOpts()
    {
        return $this->_opts;
    }

    public function getLog()
    {
        return $this->_log;
    }

    public function getReturnCode()
    {
        // no error
        return self::RETCODE_OK;
    }

    public function getInfo()
    {
    }

    protected function _connectMysql()
    {
        /** connect to DB **/
        $this->_log->start("DB connection");
        $this->_db = new PDO(
            "mysql:host=localhost;dbname=mysql",
            $this->_opts['user'],
            $this->_opts['password'],
            array(
                // enable data load command
                PDO::MYSQL_ATTR_LOCAL_INFILE=>1,
                // let PDO throw exception on errors
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                // use UTF8 for object names
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
            )
        );
        $this->_log->end();
    }

    public function restore()
    {
        $this->validateOpts($this->_opts);

        /** connect to DB **/
        $this->_connectMysql();

        // prepare shorter variable names
        $log = $this->_log;
        $db = $this->_db;
        $opts = &$this->_opts;
        $folder = &$this->_backupFolder;

        // drop database if requested
        if ($opts['drop-db']) {
            $log->start("DB drop");
            $this->_db->exec("DROP DATABASE IF EXISTS `$opts[database]`");
        }

        // create database
        $log->start("DB prepare");
        $sql = file_get_contents($folder. '/db/_create');
        $sql = str_replace("`$this->_originalDbName`", "`$opts[database]`", $sql);
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
        $log->start("USERS create");
        $this->execSqlFromFolder($folder."users/");

        // create functions
        $log->start("FUNCTIONS create");
        $this->execSqlFromFolder($folder."functions/");

        // create tables
        $log->start("TABLES create");
        $this->execSqlFromFolder($folder."tables/");

        // import data
        if (!$opts['no-data']) {
            // TODO detect if mysql server is on localhost
            $log->start("DATA load (local server)");
            $this->importDataFromFolderToLocalServer($folder."data/");
        }

        // create index
        $log->start("INDEXES create");
        $this->execSqlFromFolder($folder."indexes/");

        // create references
        $log->start("REFERENCES create");
        $this->execSqlFromFolder($folder."refs/");

        // create views
        $log->start("VIEWS create");
        $this->execSqlFromFolder($folder."views/", true);

        // create procedures
        $log->start("PROCEDURES create");
        $this->execSqlFromFolder($folder."procedures/");

        // create triggers
        $log->start("TRIGGERS create");
        $this->execSqlFromFolder($folder."triggers/");

        // create grants
        $log->start("GRANTS apply");
        $this->execSqlFromFolder($folder."grants/");

        /*
        // finish import
        $sql = <<<SQL

SQL;
        $log->start("DB finish");
        $db->exec($sql);*/

        $log->end();

    }
    function importDataFromFolderToRemoteServer($path, $truncate=true)
    {
        die("not supported yet");
    }

    public function decompressFile($srcFile, $dstFile)
    {
        echo "decompress $srcFile to $dstFile\n";
        $fp = fopen($dstFile, "w");
        $zp = gzopen($srcFile, "r");
        while(!gzeof($zp)) {
            $buf = gzread($zp, 1024*1024);
            fwrite($fp, $buf, strlen($buf));
        }
        fclose($fp);
        gzclose($zp);
    }

    /**
     * @return string false if this file should be skipped, pass other values to _handleCompressedFileAction()
     */
    protected function _handleCompressedFile(&$fileName, $checkDataFolder=true)
    {
        $info = pathinfo($fileName);
        if (!array_key_exists('extension', $info) || $info['extension']!="z") {
            // it is not compressed file
            return "";
        }

        if (!array_key_exists('filename', $info)) {
            // PHP <5.2 compatibility
            $info['filename'] = substr($info['basename'], 0, -(1+strlen($info['extension'])));
        }

        if ($checkDataFolder && file_exists($this->_backupFolder.'data'.DIRECTORY_SEPARATOR.$info['filename'])) {
            // skip it, don't worry it will be imported
            return false;
        }

        // we need to decompress the file
        $srcFile = $fileName;
        $fn = $info['filename'];
        $fileName = $this->_opts['decompress-folder'].$fn;

        if (!file_exists($fileName)) {
            // decompressed if doesn't exists already
            $this->decompressFile($srcFile, $fileName);
        }

        switch ($this->_opts['decompress-action']) {
            case 'delete':
                return $fileName;
            case 'keep':
                return "";
            case 'replace':
                return $srcFile;
            default:
                throw new Exception("Was validateOpts() called ? Unknown decompress action '".$this->_opts['decompress-action']."'.");
        }
    }

    function _handleCompressedFileAction($action)
    {
        if ($action) {
            unlink($action);
        }
    }

    public function decompressOnly()
    {
        $this->validateOpts($this->_opts, self::VALIDATE_DECOMPRESS);

        $path = $this->_backupFolder.'data'.DIRECTORY_SEPARATOR;
        if (file_exists($path) && false!==($handle = opendir($path))) {
            while (false !== ($fn = readdir($handle))) {
                if ($fn!="." && $fn!="..") {
                    $fullFn = $path.$fn;
                    if (false===($afterAction = $this->_handleCompressedFile($fullFn, false))) {
                        // skip this file
                        continue;
                    }

                    // maybe delete some files after import
                    //$this->_handleCompressedFileAction($afterAction);
                }
            }
            closedir($handle);
        }

    }

    function importDataFromFolderToLocalServer($path, $truncate=true)
    {
        if (file_exists($path) && false!==($handle = opendir($path))) {
            while (false !== ($fn = readdir($handle))) {
                if ($fn!="." && $fn!="..") {
                    $fullFn = $path.$fn;
                    if (false===($afterAction = $this->_handleCompressedFile($fullFn))) {
                        // skip this file
                        continue;
                    }

                    $fn = basename($fullFn);
                    echo "import data to table '$fn'\n";
                    if ($truncate) {
                        $this->_db->exec("TRUNCATE TABLE `$fn`");
                    }

                    // TODO LINES TERMINATED BY should maybe be configurable on command line or stored in/db/_config
                    $this->_db->exec("LOAD DATA INFILE '$fullFn' INTO TABLE `$fn` CHARACTER SET UTF8 LINES TERMINATED BY '\r\n';");

                    // maybe delete some files after import
                    $this->_handleCompressedFileAction($afterAction);
                }
            }
            closedir($handle);
        }
    }

    function execSqlFromFolderTogether($path)
    {
        // doesn't seam to be faster localy and there is risk, that the SQL command will be too long
        // some speed improvement should be visible if executed on remote server
        // TODO we have to play with this more
        global $db;

        $sql = "";
        if (file_exists($path) && false!==($handle = opendir($path))) {
            while (false !== ($fn = readdir($handle))) {
                if ($fn!="." && $fn!="..") {
                    $fullFn = $path.$fn;
                    $sql .= file_get_contents($fullFn);
                }
            }

            $this->_db->exec($sql);

            closedir($handle);
        }
    }

    function execSqlFromFolder($path, $tryRepeat=false)
    {
        $repeat = array();
        if (file_exists($path) && false!==($handle = opendir($path))) {
            while (false !== ($fn = readdir($handle))) {
                if ($fn!="." && $fn!="..") {
                    $fullFn = $path.$fn;
                    $sql = file_get_contents($fullFn);
                    try {
                        $this->_db->exec($sql);
                    } catch (Exception $e) {
                        if ($tryRepeat) {
                            if (!array_key_exists($fullFn, $repeat)) {
                                $repeat[$fullFn] = array($e);
                            } else {
                                $repeat[$fullFn][] = $e;
                            }
                        } else {
                            throw new Exception("ERROR: in file '$fullFn'" , null, $e);
                        }
                    }
                }
            }
            closedir($handle);
        }

        if ($repeat) {
            $this->execSqlFromArray($repeat);
        }

    }

    function execSqlFromArray($repeatFiles)
    {
        $tryRepeat = true;

        $repeat = array();
        foreach (array_reverse($repeatFiles) as $fullFn=>$es) {
            $sql = file_get_contents($fullFn);
            try {
                $this->_db->exec($sql);
            } catch (Exception $e) {
                if ($tryRepeat) {
                    if (!array_key_exists($fullFn, $repeat)) {
                        $repeat[$fullFn] = array($e);
                    } else {
                        $repeat[$fullFn][] = $e;
                    }
                } else {
                    throw new Exception("ERROR: in file '$fullFn'" , null, $e);
                }
            }

        }

        if (count($repeat)==0) {
            // all done
            return;
        }

        // if again the same array was produced we have to stop
        if (count(array_diff(array_keys($repeatFiles), array_keys($repeat)))==0) {
            throw new ExceptionUnsolvable($repeat);
        }

        // try again
        $this->execSqlFromArray($repeat);
    }
}


//////////////////////////////////////
function help($opts, $message=false)
{
    $cmdName = basename(__FILE__);
    echo "Run with interpreter: php -f {$cmdName}.php -- [<parameters>] [<backup folder>]\n";
    echo "Example: php -f cli.php -- -D newdb /tmp/backup\n";

    foreach ($opts as $opt) {
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

class ExceptionUnsolvable extends Exception
{
    public function __construct($filesWithErrors)
    {
        $this->_filesWithErrors = $filesWithErrors;
    }

    public function getFilesWithErrors()
    {
        return $this->_filesWithErrors;
    }

    public function __toString()
    {
        return "Unsolvable errors exists:\n".print_r($this->_filesWithErrors, true);
    }
}

class Log
{
    // thx http://davidwalsh.name/php-timer-benchmark
    protected $_start;
    protected $_text = false;
    protected $_doEcho;

    public function __construct($echo)
    {
        $this->_doEcho = $echo;
    }

    public function __destruct()
    {
        if ($this->_text!==false) {
            echo '!!! incorrect log shutdown'.PHP_EOL;
            echo $this->_text.' ... duration '.$this->get().' seconds'.PHP_EOL;
        }
    }

    protected function _echo()
    {
        if ($this->_doEcho && $this->_text!==false) {
            echo $this->_text.' ... duration '.$this->get().' seconds'.PHP_EOL;
        }
        $this->_text = false;
    }

    function start($text, $echoOnStart=true)
    {
        $this->_echo();
        $this->_start = $this->getTime();
        $this->_text = $text;

        if ($echoOnStart && $this->_doEcho && $this->_text!==false) {
            echo $this->_text.' ... START'.PHP_EOL;
        }
    }

    function end()
    {
        $this->_echo();
    }

    /*  get the current timer value  */
    function get($decimals = 8)
    {
        return round(($this->getTime() - $this->_start), $decimals);
    }

    /*  format the time in seconds  */
    function getTime()
    {
        $usec = $sec = 0;
        list($usec, $sec) = explode(' ', microtime());
        return ((float) $usec + (float) $sec);
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

    $inswitch_userkey = $inswitch_key = "";
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