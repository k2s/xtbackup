<?php
class Storage_Mysql extends Storage_Filesystem implements Storage_Mysql_IStore
{
    /**
     * @var PDO
     */
    protected $_db;
    protected $_driver;
    protected $_debugFolder;
    /**
     * @param Core_Engine  $engine
     * @param Output_Stack $output
     * @param array        $options
     *
     * @return \Storage_Filesystem
     */
    public function  __construct($identity, $engine, $output, $options)
    {
        // filesystem options
        parent::__construct($identity, $engine, $output, $options);

        // mysql options
        if (!array_key_exists('dbname', $this->_options)) {
            $this->_out->stop("parameter 'dbname' is required by driver '{$this->_identity}'");
        }
    }

    protected function _clearFolder($str, $first=true)
    {
       if(is_file($str)){
            return @unlink($str);
        }
        elseif(is_dir($str)){
            $scan = glob(rtrim($str,'/').'/*');
            if (!$scan) {
                // empty directory
                return;
            }
            foreach($scan as $index=>$path){
                $this->_clearFolder($path, false);
            }
            if (!$first) {
                @rmdir($str);
            }
            return;
        }
    }

    static public function getConfigOptions($part=null)
    {
        // new options
        $opt = array(
            CfgPart::DEFAULTS=>array(
                'host'=>'localhost',
                'port'=>'3306',
                'user'=>'root',
                'password'=>'',
                'compressdata'=>false,
                'addtobasedir'=>'',
            ),
            CfgPart::DESCRIPTIONS=>array(
                'host'=>'mysql server host name',
                'port'=>'mysql server port number',
                'user'=>'mysql user name',
                'password'=>'mysql user password',
                'dbname'=><<<TXT
Database to backup if string or array of databases to backup.
If array it may be string expressing name of db or array overriding default settings.
If it is string starting with / then the text is executed as SQL command and its results are used instead.

Override of following settings is possible: dbname (array key is used if not provided), addtobasedir, compressdata.
Consult examples in examples/mysql folder.',
TXT
                ,
                'dbname.sql'=>"SQL select which will replace dbname with multiple values (eg. SELECT schema_name as dbname, false as compressdata FROM `information_schema`.`schemata` WHERE schema_name not in ('mysql', 'information_schema'))",
                'addtobasedir'=>'',
                'compressdata'=>'compress data files on the fly',
            ),
            CfgPart::REQUIRED=>array('dbname')
        );

        // add old options from Storage_Filesystem
        //Core_Engine::array_merge_configOptions(parent::getConfigOptions(), $opt);
        $optOld = parent::getConfigOptions();
        foreach ($opt as $k=>&$o) {
            if (array_key_exists($k, $optOld)) {
                $o = Core_Engine::array_merge_recursive_distinct($optOld[$k], $o);
            }
        }
        foreach ($optOld as $k=>$o) {
            if (!array_key_exists($k, $opt)) {
                $opt[$k] = $o;
            }
        }



        if (is_null($part)) {
            return $opt;
        } else {
            return array_key_exists($part, $opt) ? $opt[$part] : array();
        }
    }

    public function storeDbObject($kind, $name, $def)
    {
        if ($this->_debugFolder) {
            // debug output
            $f = fopen($this->_debugFolder."$kind.sql", "a+");
            fputs($f, "-- $name ($kind)".PHP_EOL.$def.PHP_EOL.PHP_EOL);
            fclose($f);
        }
        $fn = $this->_baseDir.$kind;
        @mkdir($fn);
        $fn = $fn.DIRECTORY_SEPARATOR.$name;

        file_put_contents($fn, $def);
    }

    public function storeFilenameFor($kind, $name)
    {
        $fn = $this->_baseDir.$kind;
        @mkdir($fn);
        return $fn.DIRECTORY_SEPARATOR.$name;
    }

    protected function _sqlToDbNames($sql)
    {
        $this->_out->logDebug("retrieving list of DBs to backup from SQL: ".$sql);
        $q = $this->_db->query($sql);
        $ret = $q->fetchAll(PDO::FETCH_ASSOC);

        if (count($ret)) {
            if (!array_key_exists("dbname", $ret[0])) {
                throw new Core_StopException("Column name `dbname` missing in result of SQL command `$sql`.", "retrieving DB names to backup");
            }
        }

        return $ret;
    }

    public function init($myrole, $drivers)
    {
        parent::init($myrole, $drivers);

        // make sure we are not restoring
        if ($myrole===Core_Engine::ROLE_REMOTE) {
            // TODO implement restore
            $this->_out->stop("MySql restore is not supported yet.");
        }

        // connect to mysql
        $this->_db = new PDO(
            "mysql:host=".$this->_options['host'].";dbname=mysql",
            $this->_options['user'],
            $this->_options['password'],
            array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8")
        );
        // let PDO throw exception on errors
        $this->_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // because of data amount we shouldn't use buffered queries
        $this->_db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        $this->_driver = $this->_getBackupDriver();

        // build list of databases to backup
        $dbname = $this->_options['dbname'];
        $dbsToBackup = array();
        if (is_string($dbname)) {
            if ($dbname=='*') {
                // all dbs
                $this->_out->logNotice("retrieving names of all DBs from MySql server");
                $dbname = $this->_sqlToDbNames("SELECT schema_name as dbname FROM `information_schema`.`schemata` WHERE schema_name not in ('information_schema')");
            } elseif ($dbname[0]=='/') {
                // SQL
                $this->_out->logNotice("retrieving DB names based on provided SQL from MySql server");
                $dbname = $this->_sqlToDbNames(substr($dbname, 1));
            } else {
                // dbname only
                $dbsToBackup = array($dbname=>array(
                    'dbname'=>$dbname,
                    'addtobasedir'=>$this->_options['addtobasedir']
                ));
            }
        }
        if (is_array($dbname)) {
            // array or SQL was provided
            foreach ($dbname as $k=>&$v) {
                if (is_array($v)) {
                    if (!array_key_exists('dbname', $v)) {
                        $v['dbname'] = $k;
                    }
                } else {
                    $v = array(
                        'dbname'=>$v,
                        'addtobasedir'=>$v
                    );
                }
            }
            $dbsToBackup = $dbname;
        }

        // fix missing defaults
        foreach ($dbsToBackup as $dbname=>&$dbConfig) {
            if (!array_key_exists('dbname', $dbConfig)) {
                $dbConfig['dbname'] = $dbname;
            }
            if (!array_key_exists('compressdata', $dbConfig)) {
                $dbConfig['compressdata'] = $this->_options['compressdata'];
            }
            if (!array_key_exists('addtobasedir', $dbConfig)) {
                $dbConfig['addtobasedir'] = $dbConfig['dbname'];
            }
            // TODO filter options
        }

        // backup database(s)
        $originalBaseDir = $this->_baseDir;
        unset($dbConfig);
        foreach ($dbsToBackup as $dbConfig) {
            $this->_baseDir = $originalBaseDir;

            $this->_driver->setDatabaseToBackup($dbConfig['dbname']);
            $this->_driver->setDataCompression($dbConfig['compressdata']);

            if ($dbConfig['addtobasedir']) {
                $this->_baseDir .= $dbConfig['addtobasedir'].DIRECTORY_SEPARATOR;
                @mkdir($this->_baseDir);
            }

            // check/clear target folder
            if (file_exists($this->_baseDir)) {
                // TODO check if it is empty and if we are allowed to delete it if not
                $this->_out->logWarning("removing existing content from backup folder '$this->_baseDir'");
                $this->_clearFolder($this->_baseDir);
            }

            // prepare debug file if needed
            if (isset($this->_options['_debugFolder']) && $this->_options['_debugFolder']) { // undocumented config option
                $this->_debugFolder = $this->_baseDir.DIRECTORY_SEPARATOR."debug".DIRECTORY_SEPARATOR;
            }
            if ($this->_debugFolder) {
                $this->_clearFolder($this->_debugFolder);
                @mkdir($this->_debugFolder);
                $f = fopen($this->_debugFolder."1.sql", "w");
                fputs($f, <<<SQL
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
SQL
                );
                fclose($f);
            }

            // retrieve all objects known in DB
            $objects = $this->_driver->listAvailableObjectsToBackup();
            // TODO filter objects which should be backed up

            // execute backup of DB objects
            $msg = "creating backup of DB '$dbConfig[dbname]' to folder '$this->_baseDir'";
            if ($dbConfig['compressdata']) {
                $msg .= ", data will be online compressed";
            }
            $this->_out->logNotice($msg);
            $this->_driver->doBackup($this);
            $this->_out->logNotice("adding restore script");
            $this->_driver->addRestoreScript($this->_baseDir);
        }

        $this->_baseDir = $originalBaseDir;
        // $this->_out->stop("ok");

        return true;
    }

    protected function _getBackupDriver()
    {
        // TODO choose best driver class based on server version
/*        $attributes = array(
    "AUTOCOMMIT", "ERRMODE", "CASE", "CLIENT_VERSION", "CONNECTION_STATUS",
    "ORACLE_NULLS", "PERSISTENT", "PREFETCH", "SERVER_INFO", "SERVER_VERSION",
    "TIMEOUT"
);

        foreach ($attributes as $val) {
            echo "PDO::ATTR_$val: ";
            echo $this->_db->getAttribute(constant("PDO::ATTR_$val")) . "\n";
        }*/
        $driver = new Storage_Mysql_Backup5x0x2();
        $driver->setConnection($this->_db);
        $driver->setOutput($this->_out);
        return $driver;
    }
}