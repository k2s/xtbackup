<?php
class Storage_Mysql extends Storage_Filesystem implements Storage_Mysql_IStore
{
    protected $_db;
    protected $_driver;
    protected $_debugFileName;
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
    }

    protected function _clearFolder($str, $first=true)
    {
       if(is_file($str)){
            return @unlink($str);
        }
        elseif(is_dir($str)){
            $scan = glob(rtrim($str,'/').'/*');
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
        $opt = array(
            CfgPart::DEFAULTS=>array(
                'host'=>'localhost',
                'port'=>'3306',
                'user'=>'root',
                'password'=>'',
            ),
            CfgPart::DESCRIPTIONS=>array(
                'host'=>'mysql server host name',
                'port'=>'mysql server port number',
                'user'=>'mysql user name',
                'password'=>'mysql user password',
            ),
            CfgPart::REQUIRED=>array()
        );

        // merge with Storage_Filesystem options
        $opt = Core_Engine::array_merge_recursive_distinct(parent::getConfigOptions(CfgPart::DEFAULTS), $opt);

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
        $this->_driver->setDatabaseToBackup("informaglobal");

        // check/clear target folder
        // TODO check if it is empty and if we are allowed to delete it if not
        $this->_clearFolder($this->_baseDir);

        // prepare debug file if needed
        $this->_debugFolder = $this->_baseDir.DIRECTORY_SEPARATOR."debug".DIRECTORY_SEPARATOR;
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
        $this->_driver->doBackup($this);
        $this->_driver->addRestoreScript($this->_baseDir);

        $this->_out->stop("ok");

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
        return $driver;
    }
}