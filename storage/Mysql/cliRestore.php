<?php
define('GETOPT_NOTSWITCH', 0); // Internal use only
define('GETOPT_SWITCH', 1);
define('GETOPT_ACCUMULATE', 2);
define('GETOPT_VAL', 3);
define('GETOPT_MULTIVAL', 4);
define('GETOPT_KEYVAL', 5);

// parse command line
try {
    $options = array(
        'user' => array('switch' => array('u', 'user'), 'type' => GETOPT_VAL, 'default' => 'root', 'help' => 'mysql user name under which we will perform restore'),
        'password' => array('switch' => array('p', 'password'), 'type' => GETOPT_VAL, 'default' => (string)'', 'help' => 'mysql user password'),
        'host' => array('switch' => array('h', 'host'), 'type' => GETOPT_VAL, 'default' => 'localhost', 'help' => 'mysql server host name'),
        'port' => array('switch' => array('P', 'port'), 'type' => GETOPT_VAL, 'default' => 3306, 'help' => 'mysql server port number'),
        'socket' => array('switch' => array('S', 'socket'), 'type' => GETOPT_VAL, 'help' => 'mysql server port number'),
//        'directory' => array('switch' => array('d', 'directory'), 'type' => GETOPT_VAL, 'help' => 'directory with backup data'),
        'database' => array('switch' => array('D', 'database'), 'type' => GETOPT_VAL, 'help' => 'target database name'),
        'drop-db' => array('switch' => array('drop-db'), 'type' => GETOPT_SWITCH, 'help' => 'will drop DB if exists'),
        'no-data' => array('switch' => array('no-data'), 'type' => GETOPT_SWITCH, 'help' => 'skip data import'),
        'force-local-server' => array('switch' => array('force-local-server'), 'type' => GETOPT_SWITCH, 'help' => 'force data load to use method optimal for local server'),
        'actions' => array('switch' => array('a', 'actions'), 'type' => GETOPT_VAL, 'default' => 'u,f,t,i,d,r,v,p,tr,g', 'help' => <<<TXT
restore actions to execute (default is u,f,t,i,d,r,v,p,tr,g):
u - users
f - functions
t - structure of tables
i - indexes
d - table data
r - references
v - views
p - procedures
tr- triggers
g - permission grants
TXT
        ),
        'user-handling' => array('switch' => array('user-handling'), 'type' => GETOPT_VAL, 'default' => 'check', 'help' => '(check|create|with-password|only-list) how to restore user accounts'),
        'create-index' => array('switch' => array('create-index'), 'type' => GETOPT_VAL, 'default' => 'before', 'help' => '(before|after) data load'),
        'filter-ext' => array('switch' => array('F', 'filter-ext'), 'type' => GETOPT_VAL, 'help' => 'external command which returns 1 if object action should be processes'),
        'clone-to' => array('switch' => array('C', 'clone-to'), 'type' => GETOPT_VAL, 'help' => 'provide folder where you want to copy backup data, filter will be applied, if value ends with zip data will be compressed'),
        'decompress-only' => array('switch' => array('do', 'decompress-only'), 'type' => GETOPT_SWITCH, 'help' => 'decompress data files only'),
        'decompress-method' => array('switch' => array('decompress-method'), 'type' => GETOPT_VAL, 'default' => 'php-gz', 'help' => '(php-gz=internal PHP zlib, gunzip=system utility) decompress data files only'),
        'decompress-folder' => array('switch' => array('df', 'decompress-folder'), 'type' => GETOPT_VAL, 'help' => 'if data have to be uncompressed it will happen into data folder, you may change this with this option'),
        'decompress-action' => array('switch' => array('da', 'decompress-action'), 'type' => GETOPT_VAL, 'default' => 'delete', 'help' => <<<TXT
if data had to be decompressed on import this will happen after import completes:
\tdelete - delete decompressed
\tkeep - keep decompressed and compressed
\treplace - keep decompressed and delete compressed
TXT
        ),
        'remove-files' => array('switch' => array('remove-files'), 'type' => GETOPT_VAL, 'help' => '(csv=decompressed data files|z=compressed data files) data load, for security reason you have to add ^ before value to physically remove files'),
        'force' => array('switch' => array('f', 'force'), 'type' => GETOPT_SWITCH, 'help' => 'will not prompt user to approve restore'),
        'quite' => array('switch' => array('q', 'quite'), 'type' => GETOPT_SWITCH, 'help' => 'will not print messages'),
        'log-process' => array('switch' => array('log-process'), 'type' => GETOPT_VAL, 'default' => 1, 'help' => 'print messages describing restore process (0=off, 1=on)'),
        'log-sql-warn' => array('switch' => array('log-sql-warn'), 'type' => GETOPT_VAL, 'default' => 1, 'help' => 'print MySQL server warning messages (0=off, 1=on)'),
        'log-sql-exec' => array('switch' => array('log-sql-exec'), 'type' => GETOPT_VAL, 'default' => 2, 'help' => 'print executed SQL statements (0=off, 1=all, 2=if SQL warning found)'),
        'help' => array('switch' => array('?', 'help'), 'type' => GETOPT_SWITCH, 'help' => 'display instruction how to use cli.php'),
    );
    $opts = getopts($options, $_SERVER['argv']);
} catch (Exception $e) {
    help($options);
    echo $e->getMessage() . PHP_EOL . PHP_EOL;
    exit;
}

if ($opts['help']) {
    // show help message and exit
    help($options);
    exit;
}

$folder = "";
if (count($opts['cmdline']) != 1) {
    help($options, "ERROR: you have to specify backup directory");
    exit(RestoreMysql::RETCODE_PARAM_ERROR);
} else {
    $folder = $opts['cmdline'][0];
}

// decode actions
$actionList = array(
    'u' => 'users',
    'f' => 'functions',
    't' => 'tables',
    'i' => 'indexes',
    'd' => 'data',
    'r' => 'refs',
    'v' => 'views',
    'p' => 'procedures',
    'tr' => 'triggers',
    'g' => 'grants'
);
// 'u','f','t','i','d','r','v','p','tr','g'
$origActions = $opts['actions'];
$opts['actions'] = explode(",", strtolower($opts['actions']));
$wrong = array_diff($opts['actions'], array_keys($actionList));
if (count($wrong)) {
    if (count($wrong) == 1) {
        help($options, "ERROR: parameter value '$origActions' contains wrong value: " . implode("", $wrong));
    } else {
        help($options, "ERROR: parameter value '$origActions' contains wrong values: " . implode(", ", $wrong));
    }
    exit(RestoreMysql::RETCODE_PARAM_ERROR);
}
$opts['actions'] = array_flip(array_intersect_key($actionList, array_flip($opts['actions'])));
if ($opts['no-data']) {
    // another way how to dissable data import
    unset($opts['actions']['data']);
}

// object with main restore logic
$restore = new RestoreMysql($folder, $opts);
echo $restore->getInfo();

if ($opts['decompress-only']) {
    $restore->decompressOnly();
    // end with return code
    die($restore->getReturnCode());
}

if ($opts['clone-to']) {
    $restore->cloneTo();
    // end with return code
    die($restore->getReturnCode());
}

if ($opts['remove-files']) {
    $restore->removeFiles($opts['remove-files']);
    // end with return code
    die($restore->getReturnCode());
}

if ($opts['user-handling'] === "only-list") {
    if (count($restore->missingUsers(true)) === 0) {
        echo PHP_EOL . "All DB users found." . PHP_EOL;
        die(RestoreMysql::RETCODE_OK);
    } else {
        echo PHP_EOL . "Missing DB user(s) found !" . PHP_EOL;
        die(RestoreMysql::RETCODE_MISSING_USERS);
    }
}

/** prompt if to continue **/
if (!$opts['force']) {
    echo "do you want to start restore (y<enter>) ?";
    $fp = fopen('php://stdin', 'r');
    $answer = trim(fgets($fp, 1024));
    fclose($fp);
    if (strtolower($answer) !== "y") {
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
    const RETCODE_PARAM_ERROR = 2;
    const RETCODE_MISSING_USERS = 3;

    /**
     * @var int
     */
    protected $_retCode = self::RETCODE_OK;

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
    const VALIDATE_CLONE = 2;

    public function __construct($backupFolder, $opts)
    {
        $this->_opts = $opts;

        $this->_backupFolder = $backupFolder;

        $this->_originalDbName = file_get_contents($this->_backupFolder . '/db/_name');
        if (!$this->_opts['database']) {
            $this->_opts['database'] = $this->_originalDbName;
        }

        // prepare logger and timer
        $this->_log = new Log(!$opts['quite']);
        $this->_log->setOutput('log-process', $opts['log-process']);
        $this->_log->setOutput('log-sql-warn', $opts['log-sql-warn']);
        $this->_log->setOutput('log-sql-exec', $opts['log-sql-exec']);
    }

    protected function _fixFolderName($folder)
    {
        return rtrim($folder, "/\\") . DIRECTORY_SEPARATOR;
    }

    public function validateOpts($opts, $action = self::VALIDATE_RESTORE)
    {
        $this->_backupFolder = $this->_fixFolderName($this->_backupFolder);
        if (!$this->_opts['decompress-folder']) {
            $this->_opts['decompress-folder'] = $this->_backupFolder . 'data' . DIRECTORY_SEPARATOR;
        } else {
            $this->_opts['decompress-folder'] = $this->_fixFolderName($this->_opts['decompress-folder']);
        }

        switch ($this->_opts['user-handling']) {
            case "check":
            case "create":
            case "with-password":
                break;
            default:
                throw new Exception("Unknown --user-handling parameter '" . $this->_opts['user-handling'] . "'.");
        }

        switch ($this->_opts['decompress-action']) {
            case 'delete':
            case 'keep':
            case 'replace':
                break;
            default:
                throw new Exception("Unknown decompress action '" . $this->_opts['decompress-action'] . "'.");
        }

        switch ($this->_opts['decompress-method']) {
            case 'php-gz':
            case 'gunzip':
                break;
            default:
                throw new Exception("Unknown decompress method '" . $this->_opts['decompress-method'] . "'.");
        }

        if ($action == self::VALIDATE_DECOMPRESS) {
            return;
        }

        // validate parameters for clone process

        if ($action == self::VALIDATE_CLONE) {
            return;
        }

        // validate parameters for restore process
        if (!in_array($opts['create-index'], array("before", "after"))) {
            throw new Exception("Invalid value passed to create-index option.");
        }

        if ($opts['filter-ext']) {
            // try external filter response
            if (123 != $this->_filterExt("test", "test")) {
                throw new Exception("External filter didn't returned value '123' for action 'test'. Fix the filter program.");
            }
        }
    }

    protected function _filterExt($action, $objName, $cmd = null)
    {
        if (is_null($cmd)) {
            $cmd = $this->_opts['filter-ext'];
        }
        if (!$cmd) {
            return 1;
        }

        $cmd .= $this->_opts['database'] . " $action $objName";
        $output = array();
        $ret = null;
        exec($cmd, $output, $ret);
        if ($output) {
            echo "Error output from external filter:" . PHP_EOL . implode(PHP_EOL, $output);
        }

        return $ret;
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
        return $this->_retCode;
    }

    public function setReturnCode($retCode)
    {
        $this->_retCode = $retCode;
    }

    /**
     * Output information about backup data
     *
     * @return string
     */
    public function getInfo()
    {
        // TODO print out information about backup data
    }

    protected function _connectMysql()
    {
        if ($this->_db) {
            return;
        }
        /** connect to DB **/
        $this->_log->start("establishing DB connection");
        if ($this->_opts['socket']) {
            // connect over socket
            $dsn = "mysql:unix_socket=" . $this->_opts['socket'] . ";dbname=mysql";
        } else {
            // connect over TCP/IP
            $dsn = "mysql:host=" . $this->_opts['host'] . ";port=" . $this->_opts['port'] . ";dbname=mysql";
        }
        $this->_db = new DbWrapper(
            new PDO(
                $dsn,
                $this->_opts['user'],
                $this->_opts['password'],
                array(
                    // enable data load command
                    PDO::MYSQL_ATTR_LOCAL_INFILE => 1,
                    // let PDO throw exception on errors
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    // use UTF8 for object names
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
                )
            ), $this->_log
        );
        $this->_log->end();
    }

    public static function tildeToHome(&$o)
    {
        $s = substr($o, 0, 2);
        if ($s == "~/" || $s == "~\\") {
            $o = getenv("HOME") . substr($o, 1);
        }
    }

    protected function _removeDataFiles($removeExt, $checkExt, $listOnly)
    {
        $subPath = 'data';
        $path = $this->_backupFolder . $subPath . DIRECTORY_SEPARATOR;
        if (!file_exists($path)) {
            throw new Exception("Data folder '$path' is missing.");
        }
        if (false !== ($handle = opendir($path))) {
            while (false !== ($subPath = readdir($handle))) {
                if ($subPath != "." && $subPath != "..") {
                    $parts = pathinfo($subPath);
                    if (!array_key_exists("extension", $parts)) {
                        $parts["extension"] = "";
                    }

                    if ($parts["extension"] === $removeExt) {
                        // check external
                        if (1 !== $this->_filterExt($subPath, $parts["basename"])) {
                            $this->_log->subtask()->log("$subPath: skip '$parts[filename]' because of external filter");
                            continue;
                        }

                        $counterPart = $path . $parts["filename"] . '.' . $checkExt;
                        if (file_exists($counterPart)) {
                            $this->_log->log("REMOVE: $subPath");
                            if (!$listOnly) {
                                unlink($path . $subPath);
                            }
                        } else {
                            $this->_log->log("KEEP: $subPath ... missing $counterPart");
                        }
                    }
                }
            }
            closedir($handle);
        }
    }

    public function removeFiles($what)
    {
        // check if user really wants to delete files from disk
        $forceChar = "^";
        $listOnly = true;
        if ($what[0] === $forceChar) {
            $what = substr($what, 1);
            $listOnly = false;
        }
        // process file removal
        switch ($what) {
            case "csv":
                $this->_log->start("removing extracted data files (if compressed file exists)");
                $this->_removeDataFiles("", "z", $listOnly);
                $this->_log->end();
                break;
            case "z":
                $this->_log->start("removing compressed data files (if extracted file exists)");
                $this->_removeDataFiles("z", "", $listOnly);
                $this->_log->end();
                break;
            default:
                // invalid parameter
                throw new Exception("Unknown remove-files action '" . $this->_opts['remove-files'] . "'.");
        }

        if ($listOnly) {
            $this->_log->log("This was DRY run, you have to add '$forceChar' before '$what' value to really physically remove files from disk.");
        }
    }

    public function cloneTo()
    {
        $this->_log->start("cloning backup data");

        $this->validateOpts($this->_opts, self::VALIDATE_CLONE);

        $path = $this->_backupFolder;
        $dstFolder = ltrim($this->_opts['clone-to'], "/\\") . DIRECTORY_SEPARATOR;
        self::tildeToHome($dstFolder);
        @mkdir($dstFolder, 0777, true);

        // check that the target folder is empty
        $handle = opendir($dstFolder);
        $isEmpty = true;
        while (false !== ($fn = readdir($handle))) {
            if ($fn != "." && $fn != "..") {
                $isEmpty = false;
                break;
            }
        }
        closedir($handle);
        if (!$isEmpty) {
            throw new Exception("Folder where backup data should be cloned has to be empty. ('$dstFolder')");
        }

        // copy files
        if (file_exists($path) && false !== ($handle = opendir($path))) {
            while (false !== ($subPath = readdir($handle))) {
                if (is_file($path . $subPath)) {
                    // restore support files
                    copy($path . $subPath, $dstFolder . $subPath);
                } elseif ($subPath != "." && $subPath != "..") {
                    // actions
                    if (!array_key_exists($subPath, $this->_opts['actions'])) {
                        // not configured to be processed in --actions
                        continue;
                    }
                    @mkdir($dstFolder . $subPath, 0777, true);
                    if (false !== ($h2 = opendir($path . $subPath))) {
                        while (false !== ($fn = readdir($h2))) {
                            // loop files/objects
                            $fullFn = $path . $subPath . DIRECTORY_SEPARATOR . $fn;
                            if ($subPath == "." || $subPath == ".." || !is_file($fullFn)) {
                                // nothing to do
                                continue;
                            }

                            if (1 !== $this->_filterExt($subPath, $fn)) {
                                $this->_log->subtask()->log("$subPath: skip '$fn' because of external filter");
                                continue;
                            }

                            $dstFn = $dstFolder . $subPath . DIRECTORY_SEPARATOR . $fn;
                            $task = $this->_log->subtask()->start("$subPath: clone '$fn' to '$dstFn'");
                            copy($fullFn, $dstFn);
                            $task->end();
                        }
                    }
                    closedir($h2);
                }
            }
            closedir($handle);
        }

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
        $opts = & $this->_opts;
        $folder = & $this->_backupFolder;

        // create users
        $log->start("USERS create");
        switch ($opts['user-handling']) {
            case 'create':
                $this->createUsers(false);
                break;
            case 'with-password':
                $this->createUsers(true);
                break;
            default:
                if (count($this->missingUsers(true))>0) {
                    $this->_log->end();
                    $this->_log->log("Restore will not continue, missing users on server found. Change --user-handling parameter to solve this problem.");
                    $this->setReturnCode(self::RETCODE_MISSING_USERS);
                    return;
                }
        }

        // drop database if requested
        if ($opts['drop-db']) {
            $log->start("DB drop");
            $this->_db->exec("DROP DATABASE IF EXISTS `$opts[database]`");
        }

        // create database
        $log->start("DB prepare");
        $sql = file_get_contents($folder . '/db/_create');
        $sql = str_replace("`$this->_originalDbName`", "`$opts[database]`", $sql);
        try {
            $db->exec($sql);
        } catch (PDOException $e) {
            if ($e->getCode() == "HY000") {
                echo $e->getMessage() . "\n";
                exit(1);
            }
            throw $e;
        }

        // change to DB
        $db->exec("use `$opts[database]`");

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

        // create functions
        $log->start("FUNCTIONS create");
        $this->execSqlFromFolder("functions");

        // create tables
        $log->start("TABLES create");
        $this->execSqlFromFolder("tables");

        if ($opts['create-index'] == "before") {
            // create index
            $log->start("INDEXES create");
            $this->execSqlFromFolder("indexes");
        }

        // import data
        if (array_key_exists("data", $opts['actions'])) {
            // detect if mysql server is on localhost
            if ($this->_opts['force-local-server'] || $this->_opts['host'] == "localhost" || $this->_opts['host'] == "127.0.0.1") {
                $log->start("DATA load (local server)");
                $this->importDataFromFolderToLocalServer("data");
            } else {
                $log->start("DATA load (remote server)");
                $this->importDataFromFolderToRemoteServer("data");
            }
        }

        if ($opts['create-index'] == "after") {
            // create index
            $log->start("INDEXES create");
            $this->execSqlFromFolder("indexes");
        }

        // create references
        $log->start("REFERENCES create");
        $this->execSqlFromFolder("refs");

        // create views
        $log->start("VIEWS create");
        $this->execSqlFromFolder("views", true);

        // create procedures
        $log->start("PROCEDURES create");
        $this->execSqlFromFolder("procedures");

        // create triggers
        $log->start("TRIGGERS create");
        $this->execSqlFromFolder("triggers");

        // create grants
        $log->start("GRANTS apply");
        $this->grantPermissions("grants");

        /*
        // finish import
        $sql = <<<SQL

SQL;
        $log->start("DB finish");
        $db->exec($sql);*/

        $log->end();

    }

    public function decompressFile($srcFile, $dstFile)
    {
//        $this->_log->log("decompress $srcFile to $dstFile");
        if ($this->_opts['decompress-method'] === "gunzip") {
            $command = 'gunzip -c ' . escapeshellcmd($srcFile) . ' > ' . escapeshellcmd($dstFile);
            system($command);
        } else {
            $fp = fopen($dstFile, "w");
            $zp = gzopen($srcFile, "r");
            while (!gzeof($zp)) {
                $buf = gzread($zp, 1024 * 1024);
                fwrite($fp, $buf, strlen($buf));
            }
            fclose($fp);
            gzclose($zp);
        }
        // TODO add file tests (file size, crc32?)
    }

    /**
     * @param $fileName
     * @param bool $checkDataFolder
     * @return bool|string false if this file should be skipped, pass other values to _handleCompressedFileAction()
     * @throws Exception
     */
    protected function _handleCompressedFile(&$fileName, $checkDataFolder = true)
    {
        $info = pathinfo($fileName);
        if (!array_key_exists('extension', $info) || $info['extension'] != "z") {
            // it is not compressed file
            return "";
        }

        if (!array_key_exists('filename', $info)) {
            // PHP <5.2 compatibility
            $info['filename'] = substr($info['basename'], 0, -(1 + strlen($info['extension'])));
        }

        if ($checkDataFolder && file_exists($this->_backupFolder . 'data' . DIRECTORY_SEPARATOR . $info['filename'])) {
            // skip it, don't worry it will be imported
            return false;
        }

        // we need to decompress the file
        $srcFile = $fileName;
        $fn = $info['filename'];
        $fileName = $this->_opts['decompress-folder'] . $fn;

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
                throw new Exception("Was validateOpts() called ? Unknown decompress action '" . $this->_opts['decompress-action'] . "'.");
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

        $subPath = 'data';
        $path = $this->_backupFolder . $subPath . DIRECTORY_SEPARATOR;
        if (file_exists($path) && false !== ($handle = opendir($path))) {
            while (false !== ($fn = readdir($handle))) {
                if ($fn != "." && $fn != "..") {
                    $fullFn = $path . $fn;

                    // evaluate filter
                    if (substr($fn, -2) === ".z") {
                        $filterFn = substr($fn, 0, -2);
                    } else {
                        $filterFn = $fn;
                    }
                    if (1 !== $this->_filterExt($subPath, $filterFn)) {
                        //$this->_log->subtask()->log("$subPath: skip '$fn' because of external filter");
                        continue;
                    }

                    if (false === ($afterAction = $this->_handleCompressedFile($fullFn, false))) {
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

    function importDataFromFolderToRemoteServer($path, $truncate = true)
    {
        return $this->importDataFromFolder(false, $path, $truncate);
    }


    function importDataFromFolderToLocalServer($subPath, $truncate = true)
    {
        return $this->importDataFromFolder(true, $subPath, $truncate);
    }

    function importDataFromFolder($isLocalHost, $subPath, $truncate = true)
    {
        $path = $this->_backupFolder . $subPath . DIRECTORY_SEPARATOR;
        if (file_exists($path) && false !== ($handle = opendir($path))) {
            while (false !== ($fn = readdir($handle))) {
                if ($fn != "." && $fn != "..") {

                    if (1 !== $this->_filterExt($subPath, $fn)) {
                        $this->_log->subtask()->log("$subPath: skip '$fn' because of external filter");
                        continue;
                    }

                    $fullFn = realpath($path) . DIRECTORY_SEPARATOR . $fn;
                    if (false === ($afterAction = $this->_handleCompressedFile($fullFn))) {
                        // skip this file
                        continue;
                    }

                    // the name could be changed in _handleCompressedFile()
                    $fn = basename($fullFn);

                    if ($truncate) {
                        $task = $this->_log->subtask()->start("truncating data in table '$fn'");
                        $this->_db->exec("TRUNCATE TABLE `$fn`");
                        $task->end();
                    }

                    $task = $this->_log->subtask()->start("import data to table '$fn' from file '$fullFn'");
                    // TODO LINES TERMINATED BY should maybe be configurable on command line or stored in/db/_config
                    // ALTER TABLE `$fn` DISABLE KEYS; ALTER TABLE `$fn` ENABLE KEYS;
                    $local = $isLocalHost ? "" : "LOCAL";
                    $this->_db->exec(<<<SQL
LOAD DATA $local INFILE '$fullFn' INTO TABLE `$fn` CHARACTER SET UTF8 LINES TERMINATED BY '\r\n';
SQL
                    );
                    $task->end();

                    // maybe delete some files after import
                    $this->_handleCompressedFileAction($afterAction);
                }
            }
            closedir($handle);
        }

        return true;
    }

    function execSqlFromFolderTogether($path)
    {
        // doesn't seam to be faster localy and there is risk, that the SQL command will be too long
        // some speed improvement should be visible if executed on remote server
        // TODO we have to play with this more

        $sql = "";
        if (file_exists($path) && false !== ($handle = opendir($path))) {
            while (false !== ($fn = readdir($handle))) {
                if ($fn != "." && $fn != "..") {
                    $fullFn = $path . $fn;
                    $sql .= file_get_contents($fullFn);
                }
            }

            $this->_db->exec($sql);

            closedir($handle);
        }
    }

    function execSqlFromFolder($subPath, $tryRepeat = false)
    {
        if (!array_key_exists($subPath, $this->_opts['actions'])) {
            return;
        }
        $path = $this->_backupFolder . $subPath . DIRECTORY_SEPARATOR;
        $repeat = array();
        if (file_exists($path) && false !== ($handle = opendir($path))) {
            while (false !== ($fn = readdir($handle))) {
                if ($fn != "." && $fn != "..") {
                    $fullFn = $path . $fn;

                    if (1 !== $this->_filterExt($subPath, $fn)) {
                        $this->_log->subtask()->log("skip '$fn' because of external filter");
                        continue;
                    }

                    $task = $this->_log->subtask()->start("$subPath: apply '$fn'");
                    $sql = file_get_contents($fullFn);
                    try {
                        $this->_db->exec($sql);
                    } catch (Exception $e) {
                        $task->end();
                        if ($tryRepeat) {
                            if (!array_key_exists($fullFn, $repeat)) {
                                $repeat[$fullFn] = array($e);
                            } else {
                                $repeat[$fullFn][] = $e;
                            }
                        } else {
                            throw new Exception("ERROR: in file '$fullFn'", null, $e);
                        }
                    }
                    $task->end();
                }
            }
            closedir($handle);
        }

        if ($repeat) {
            //$this->_log->write("repeating with ...");
            $this->execSqlFromArray($repeat);
        }

    }

    function execSqlFromArray($repeatFiles)
    {
        $tryRepeat = true;

        $repeat = array();
        foreach (array_reverse($repeatFiles) as $fullFn => $es) {
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
                    throw new Exception("ERROR: in file '$fullFn'", null, $e);
                }
            }

        }

        if (count($repeat) == 0) {
            // all done
            return;
        }

        // if again the same array was produced we have to stop
        if (count(array_diff(array_keys($repeatFiles), array_keys($repeat))) == 0) {
            throw new ExceptionUnsolvable($repeat);
        }

        // try again
        $this->execSqlFromArray($repeat);
    }

    protected function _parseUserHost($s, &$user, &$host)
    {
        list($user, $host) = explode('@', $s, 2);
        $user = str_replace("'", "", $user);
        $host = str_replace("'", "", $host);
    }

    protected function _userList()
    {
        $users = file($this->_backupFolder . '/users/users');
        foreach ($users as &$l) {
            $l = trim($l);
            $l = explode(':', $l, 2);
            $this->_parseUserHost($l[0], $l['user'], $l['host']);
        }
        return $users;
    }

    public function missingUsers($print = false)
    {
        $missing = array();

        $this->_connectMysql();

        foreach ($this->_userList() as $u) {
            $exits = $this->_db->query(
                sprintf("SELECT count(*) FROM mysql.user WHERE user = %s and host = %s",
                    $this->_db->quote($u['user']), $this->_db->quote($u['host'])
                )
            )->fetchColumn();
            if (!$exits) {
                if ($print) {
                    $this->_log->log('! missing DB user:' . $u[0]);
                }
                $missing[] = $u;
            }
        }

        return $missing;
    }

    function createUsers($withPassword)
    {
        $missing = $this->missingUsers();
        foreach ($missing as $u) {
            $sql = "CREATE USER $u[0]";
            if ($withPassword) {
                if ($u[1]) {
                    $sql .= " IDENTIFIED BY '$u[1]'";
                } else {
                    $this->_log->log("! security risk: user '$u[0]' contains empty password in backup");
                }
            } else {
                $this->_log->log("! security risk: user '$u[0]' was created with empty password");
            }
            $this->_db->exec($sql);
        }
    }

    function grantPermissions()
    {
        $dbName = $this->_opts['database'];
        if (($f = fopen($this->_backupFolder . '/users/grants', "r")) !== FALSE) {
            while (($d = fgetcsv($f, 1000, ",")) !== FALSE) {
                $sql = "GRANT $d[0] ON `$dbName`$d[1] TO $d[2] $d[3]";
                $this->_db->exec($sql);
            }
            fclose($f);
        }
    }
}


//////////////////////////////////////
function help($opts, $message = false)
{
    echo "xtbackup MySQL Restore (http://github.com/k2s/xtbackup)" . PHP_EOL . PHP_EOL;

    $cmdName = basename(__FILE__);
    echo "Run with interpreter: php -f {$cmdName}.php -- [<parameters>] [<backup folder>]\n";
    echo "Example: php -f cli.php -- -D newdb /tmp/backup\n";

    foreach ($opts as $opt) {
//        var_dump($opt);die();
        if (isset($opt['switch'][1]) && isset($opt['switch'][0])) {
            echo '-' . $opt['switch'][0] . ', --' . $opt['switch'][1] . (isset($opt['help']) ? "\t" . $opt['help'] : '') . "\n";
        } elseif (isset($opt['switch'][0])) {
            $delimiter = strlen($opt['switch'][0]) > 1 ? '--' : '-';
            echo $delimiter . $opt['switch'][0] . (isset($opt['help']) ? "\t\t" . $opt['help'] : '') . "\n";
        } else {
            continue;
        }
    }

    if (false !== $message) {
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
        return "Unsolvable errors exists:\n" . print_r($this->_filesWithErrors, true);
    }
}

class DbWrapper
{
    /**
     * @var PDO
     */
    private $_db;
    /**
     * @var Log
     */
    private $_log;

    public function __construct(PDO $db, $log)
    {
        $this->_db = $db;
        $this->_log = $log;
    }

    public function exec($sql)
    {
        $this->_log->sql($sql);
        return $this->_checkWarnings($this->_db->exec($sql));
    }

    public function query($sql)
    {
        $this->_log->sql($sql);
        return $this->_checkWarnings($this->_db->query($sql));
    }

    public function quote($s)
    {
        return $this->_db->quote($s);
    }

    protected function _checkWarnings($ret)
    {
        $warnings = $this->_db->query('SHOW WARNINGS')->fetchAll();
        if (count($warnings)) {
            $c = 0;
            foreach ($warnings as $w) {
                if ($w["Level"] !== "Note") {
                    $this->_log->sqlWarn("$w[Level]: $w[Message]");
                    $c++;
                }
            }
        }
        return $ret;
    }
}

class Log
{
    // thx http://davidwalsh.name/php-timer-benchmark
    protected $_start;
    protected $_text = false;
    protected $_doEcho;
    protected $_taskLevel = 0;
    protected $_prefix = "";
    protected $_output;
    protected $_wrap = "log-process";
    public $_sql = "";

    public function __construct($echo, $taskLevel = 0)
    {
        $this->_doEcho = $echo;
        $this->setTaskLevel($taskLevel);
    }

    public function __destruct()
    {
        if ($this->_text !== false && $this->_taskLevel == 0) {
            $this->_out('!!! incorrect log shutdown' . PHP_EOL);
            $this->_out(sprintf("%s ... duration %f second(s)", $this->_text, $this->get()) . PHP_EOL);
        }
    }

    public function setTaskLevel($taskLevel)
    {
        $this->_taskLevel = $taskLevel;
        $this->_prefix = str_repeat("\t", $this->_taskLevel);
    }

    public function setOutput($kind, $value)
    {
        $this->_output[$kind] = $value;
    }

    public function wrapOutput($kind, $func)
    {
        $old = $this->_wrap;
        $this->_wrap = $kind;
        $func();
        $this->_wrap = $old;
    }

    protected function _out($s)
    {
        if ($this->_output[$this->_wrap]) {
            echo $s;
        }
    }

    public function subtask()
    {
//        $task = new Log($this->_doEcho, $this->_taskLevel + 1);
        $task = clone $this;
        $task->setTaskLevel($this->_taskLevel + 1);
        return $task;
    }

    public function log($message)
    {
        $this->_out($this->_prefix . $message . PHP_EOL);
    }

    public function sql($sql)
    {
        $s = "-- SQL\n$sql\n-- SQL\n";
        if ($this->_output['log-sql-exec'] === 1) {
            $obj = $this;
            $this->wrapOutput('log-sql-exec', function () use ($obj, $s) {
                $obj->log($s);
            });
        } else {
            $this->_sql = $s;
        }
    }

    public function sqlWarn($msg)
    {
        $obj = $this;
        if ($this->_output['log-sql-warn']) {
            if ($this->_sql) {
                if ($this->_output['log-sql-exec'] === 2) {
                    $this->wrapOutput('log-sql-exec', function () use ($obj) {
                        $obj->log($obj->_sql);
                    });
                }
                $this->_sql = "";
            }
            $this->wrapOutput('log-sql-warn', function () use ($obj, $msg) {
                $obj->log("#" . $msg);
            });
        }
    }

    protected function _echo()
    {
        if ($this->_doEcho && $this->_text !== false) {
            $this->_out(sprintf("%s ... duration %f second(s)", $this->_prefix . $this->_text, $this->get()) . PHP_EOL);
        }
        $this->_text = false;
    }

    /**
     * @param $text
     * @param bool $echoOnStart
     * @return Log
     */
    function start($text, $echoOnStart = true)
    {
        $this->_echo();
        $this->_start = $this->getTime();
        $this->_text = $text;

        if ($echoOnStart && $this->_doEcho && $this->_text !== false) {
            $this->_out($this->_prefix . $this->_text . ' ... START' . PHP_EOL);
        }

        return $this;
    }

    function end()
    {
        $this->_echo();
    }

    /*  get the current timer value  */
    function get($decimals = 6)
    {
        return round(($this->getTime() - $this->_start), $decimals);
    }

    /*  format the time in seconds  */
    function getTime()
    {
        /** @noinspection PhpUnusedLocalVariableInspection */
        $usec = $sec = 0;
        list($usec, $sec) = explode(' ', microtime());
        return ((float)$usec + (float)$sec);
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
 * @throws Exception
 */
function getopts($options, $fromarr = null)
{
    if ($fromarr === null)
        $fromarr = $_SERVER['argv'];
    elseif (!is_array($fromarr))
        $fromarr = explode(' ', $fromarr); // Split it into an array if someone passes anything other than an array
    $opts = array('cmdline' => array()); // Output options
    $optionslookup = array(); // Reverse lookup table mapping each possible option to its real $options key
    foreach ($options as $optitem => $props) { // Default all options
        if (!isset($props['type'])) { // User didnt specify type...
            $options[$optitem]['type'] = GETOPT_SWITCH; // Default to switch
            $props['type'] = GETOPT_SWITCH; // And again because we're not using pointers here
        }
        switch ($props['type']) {
            /** @noinspection PhpMissingBreakStatementInspection */
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
                if (substr($fromarr[$i], 0, 1) == '-') // Throw error if the user tries to simply set another switch while the last one is still 'open'
                    throw new Exception("The option '{$fromarr[$i]}' needs a value.\n");
                GETOPT_setval($opts, $options, $inswitch_key, $fromarr[$i]);
                $inswitch = GETOPT_NOTSWITCH; // Reset the reader to carry on reading normal stuff
                break;
            case GETOPT_KEYVAL: // Yes, the awkward one.
                if (substr($fromarr[$i], 0, 1) == '-') // Throw error if the user tries to simply set another switch while the last one is still 'open'
                    throw new Exception("The option '{$fromarr[$i]}' needs a value.\n");
                $fromarr[$i] = strtr($fromarr[$i], ':', '='); // Replace all ':' with '=' (keeping things simple and fast.
                if (strpos($fromarr[$i], '=') === false)
                    throw new Exception("The option '$inswitch_userkey' needs a key-value pair. E.g. '-$inswitch_userkey option=value'");
                GETOPT_setval($opts, $options, $inswitch_key, explode('=', $fromarr[$i]));
                $inswitch = GETOPT_NOTSWITCH; // Reset the reader to carry on reading normal stuff
                break;
            case GETOPT_NOTSWITCH: // General invokation of no previously complex cmdline options (i.e. i have no idea what to expect next)
                if (substr($fromarr[$i], 0, 1) == '-') {
                    // Probably the start of a switch
                    if ((strlen($fromarr[$i]) == 2) || (substr($fromarr[$i], 0, 2) == '--')) { // Single switch OR long opt (might be a weird thing like VAL, MULTIVAL etc.)
                        $userkey = ltrim($fromarr[$i], '-');
                        if (!isset($optionslookup[$userkey]))
                            throw new Exception("Unknown option '-$userkey'\n");
                        $hashkey = $optionslookup[$userkey]; // Replace with the REAL key
                        if (($options[$hashkey]['type'] == GETOPT_SWITCH) || ($options[$hashkey]['type'] == GETOPT_ACCUMULATE)) {
                            GETOPT_setval($opts, $options, $hashkey, 1); // Simple enough - Single option specified in switch that needs no params.
                        } else { // OK the option needs a value. This is where the fun begins
                            $inswitch = $options[$hashkey]['type']; // Set so the next process cycle will pick it up
                            $inswitch_key = $hashkey;
                            $inswitch_userkey = $userkey;
                        }
                    } else {
                        // Multiple letters. Probably a bundling
                        for ($o = 1; $o < strlen($fromarr[$i]); $o++) {
                            $hashkey = substr($fromarr[$i], $o, 1);
                            if (!isset($optionslookup[$hashkey]))
                                throw new Exception("Unknown option '-$hashkey'\n");
                            if (($options[$optionslookup[$hashkey]]['type'] != GETOPT_SWITCH) && ($options[$optionslookup[$hashkey]]['type'] != GETOPT_ACCUMULATE))
                                throw new Exception("Option '-$hashkey' requires a value.\n");
                            GETOPT_setval($opts, $options, $optionslookup[$hashkey], 1);
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

function GETOPT_setval(&$opts, &$options, $key, $value)
{
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