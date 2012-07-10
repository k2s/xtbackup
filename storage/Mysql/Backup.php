<?php
class Storage_Mysql_Backup implements Storage_Mysql_IBackup
{
    /**
     * @var PDO
     */
    protected $_db;

    /**
     * Should data files be compressed
     * @var bool
     */
    protected $_compressDataFiles = false;

    protected $_dbName;

    const KIND_DB = "db";
    const KIND_USERS = "users";
    const KIND_FUNCTIONS = "functions";
    const KIND_TABLES = "tables";
    const KIND_DATA = 'data';
    const KIND_INDEXES = 'indexes';
    const KIND_REFS = 'refs';
    const KIND_VIEWS = 'views';
    const KIND_TRIGGERS = 'triggers';
    const KIND_PROCEDURES = 'procedures';
    const KIND_GRANTS = 'grants';

    /**
     * Backup this DB objects types
     * @var array
     */
    protected $_kindsToBackup = array(
        self::KIND_DB,
        self::KIND_USERS,
        self::KIND_FUNCTIONS,
        self::KIND_TABLES,
        self::KIND_DATA,
        self::KIND_INDEXES,
        self::KIND_REFS,
        self::KIND_VIEWS,
        self::KIND_TRIGGERS,
        self::KIND_PROCEDURES,
        self::KIND_GRANTS,
    );

    protected $_cachedObjectsToBackup = array();

    function setConnection($connection)
    {
        $this->_db = $connection;
    }

    function setDatabaseToBackup($name)
    {
        $this->_dbName = $name;
        $this->_db->query("use `$name`");
        $this->_cachedObjectsToBackup = array();
    }

    function setDataCompression($compressDataFiles)
    {
        $this->_compressDataFiles = $compressDataFiles;
    }

    function addRestoreScript($folder)
    {
        $src = dirname(__FILE__).DIRECTORY_SEPARATOR.'cliRestore.php';
        $trg = $folder.'restore.php';
        copy($src, $trg);
    }

    function listAvailableObjectsToBackup($kind=false)
    {
        /*if ($kind==self::KIND_TABLES || $kind==self::KIND_DATA) {
            return array('video');
        }*/

        if ($kind===false) {
            // build list object of all kinds

            $res = array();
            foreach ($this->_kindsToBackup as $kind) {
                $res[$kind] = $this->listAvailableObjectsToBackup($kind);
            }

            return $res;
        }

        // build list of object names of given kind
        if (!isset($this->_cachedObjectsToBackup[$kind])) {
            $funcName = "_cache".ucfirst($kind);
            if (method_exists($this, $funcName)) {
                $this->$funcName();
            } else {
                echo "don't know of to build list of '$kind'.\n";
                $this->_cachedObjectsToBackup[$kind] = array();
            }

        }

        return $this->_cachedObjectsToBackup[$kind];
    }

    public function removeObject($kind, $name)
    {
        // TODO allow exclusion of objects from backup
    }

    protected function _cacheDb()
    {
        $this->_cachedObjectsToBackup[self::KIND_DB] = array($this->_dbName);
    }

    function doBackup($store)
    {
        foreach ($this->_kindsToBackup as $kind) {
            $funcName = "_backup".ucfirst($kind);
            if (method_exists($this, $funcName)) {
                $this->$funcName($store);
            } else {
                echo "don't know how to backup objects of type '$kind'.\n";
            }
        }
    }

    /**
     * @param Storage_Mysql_IStore $store
     * @return void
     */
    protected function _backupDb($store)
    {
        // script DB creation
        $def = $this->_db->query("show create DATABASE `{$this->_dbName}`;")->fetchColumn(1);
        $store->storeDbObject(self::KIND_DB, "_name", $this->_dbName);
        $store->storeDbObject(self::KIND_DB, "_create", $def);

    }
    protected function _backupRefs($store) {}
    protected function _backupIndexes($store) {}

    protected function _helperBackupCodeObject($store, $kind, $cmd, $colName)
    {
        foreach ($this->listAvailableObjectsToBackup($kind) as $def) {
            if (is_array($def)) {
                // we store multiple informations about triggers
                $def = $def[0];
            }
            $sql = $this->_db->query($cmd." `{$this->_dbName}`.`$def`")->fetchColumn($colName);
            $security = $this->_removeSecurity($kind, $sql);
            // TODO store definer
            $store->storeDbObject($kind, $def, "-- $security\n$sql");
        }
    }

    protected function _backupFunctions($store)
    {
        $this->_helperBackupCodeObject($store, self::KIND_FUNCTIONS, "SHOW CREATE FUNCTION", 2);
    }

    protected function _backupViews($store)
    {
        $this->_helperBackupCodeObject($store, self::KIND_VIEWS, "SHOW CREATE VIEW", 1);
    }

    protected function _backupProcedures($store)
    {
        $this->_helperBackupCodeObject($store, self::KIND_PROCEDURES, "SHOW CREATE PROCEDURE", 2);
    }

    protected function _backupTriggers($store)
    {
        $this->_helperBackupCodeObject($store, self::KIND_TRIGGERS, "SHOW CREATE TRIGGER", 2);
    }

    protected function _backupTables($store)
    {
        foreach ($this->listAvailableObjectsToBackup(self::KIND_TABLES) as $def) {
            // info SHOW TABLE STATUS LIKE 'TABLES';

            // collect some needed data
            $sql = <<<SQL
select column_name from information_schema.`COLUMNS` where table_name='$def' and table_schema="{$this->_dbName}" and extra like '%auto_increment%'
SQL;
            $autoIncrementField = $this->_db->query($sql)->fetchColumn();

            // structure
            $q = $this->_db->query("SHOW CREATE TABLE `{$this->_dbName}`.`$def`");
            foreach ($q->fetchAll(PDO::FETCH_NUM) as $o) {
                // modify original mysql statement
                $s = explode(PHP_EOL, $o[1]);
                $tbl = array();
                $idx = array();
                $refs = array();
                $last = "";
                foreach ($s as $t) {
                    $t = trim($t);
                    if (
                        0==strncasecmp($t, "CONSTRAINT ", 11)
                    ) {
                        // constraint found
                        $refs[] = "ADD ".$t;
                    } else if (
                        0==strncasecmp($t, "KEY ", 4) ||
                        0==strncasecmp($t, "UNIQUE KEY ", 11) ||
                        0==strncasecmp($t, "PRIMARY KEY ", 12)
                    ) {
                        // index found
                        $idx[] = "ADD ".$t;
                    } elseif ($t[0]==")") {
                        // end of column definition
                        $last = rtrim($last, ",");
                        unset($last);
                        $tbl[] = &$t;
                    } else {
                        // table definition
                        if ($autoIncrementField && 0===strncasecmp("`$autoIncrementField`", $t, 2+strlen($autoIncrementField))) {
                            // autoincrement field

                            // add to indexes
                            $idx[] = "MODIFY ".rtrim($t, ',').',';

                            // remove definition
                            // TODO safe would be to make sure, that it is not part of field name or COMMENT
                            $t = str_replace(" AUTO_INCREMENT", "", $t);
                        }
                        unset($last);
                        $last = $t;
                        $tbl[] = &$last;
                    }
                }

                // store the table
                $tbl = implode(PHP_EOL, $tbl);

                $store->storeDbObject(self::KIND_TABLES, $def, $tbl.';');

                // store tables indexes
                if (count($idx)) {
                    array_unshift($idx, "ALTER TABLE `$def`");
                    $idx[count($idx)-1] = rtrim($idx[count($idx)-1], ',');
                    $idx = implode(PHP_EOL, $idx);
                    $store->storeDbObject(self::KIND_INDEXES, $def, $idx.';');
                }

                // store table constraints
                if (count($refs)) {
                    array_unshift($refs, "ALTER TABLE `$def`");
                    $refs = implode(PHP_EOL, $refs);
                    $store->storeDbObject(self::KIND_REFS, $def, $refs.';');
                }
            }
        }
    }

    /**
     * @param string $kind
     * @param string $sql
     * @return string
     */
    protected function _removeSecurity($kind, &$sql)
    {
    /*    switch ($kind) {
            case self::KIND_FUNCTIONS:
                $keyword = " FUNCTION ";
                break;
            case self::KIND_VIEWS:
                $keyword = " VIEW ";
                break;
            default:
                die("don't know how to extract security from object kind '$kind'\n");
        }*/

        // extract security definition
        $security = "";
        $a = explode("\n", $sql);
        $b = array();
        $l=0;
        // this loop is here, because it may be good to extract also SQL SECURITY INVOKER attribute
        foreach ($a as $s) {
            if (++$l==1) {
                // search for DEFINER
                if (preg_match("/DEFINER=`([^`]+)`@`([^`]+)`/i", $s, $m, PREG_OFFSET_CAPTURE)>0) {
                    $security = $m[0][0];
                    $s = substr($s, 0, $m[0][1]-1).substr($s, $m[0][1]+strlen($m[0][0]));
                }
            }
            $b[] = $s;
        }

        $sql = implode("\n", $b);

        return $security;
    }

    protected function _backupData($store)
    {
        // TODO detect if server is localhost
        if (false) {
            $this->_backupDataFromLocal($store);
        } else {
            $this->_backupDataFromRemote($store);
        }
    }

    protected function _backupDataFromLocal($store)
    {
        // TODO not implemented
        /**
         SELECT * INTO OUTFILE "c:/mydata.csv"
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"'
LINES TERMINATED BY "\n"
FROM my_table;
         */
        foreach ($this->listAvailableObjectsToBackup(self::KIND_DATA) as $def) {

        }
    }

    protected function _backupDataFromRemote($store)
    {
        foreach ($this->listAvailableObjectsToBackup(self::KIND_DATA) as $def) {
            $fn = $store->storeFilenameFor(self::KIND_DATA, $def);
            if ($this->_compressDataFiles) {
                $f = fopen("compress.zlib://".$fn.".z", "w");
            } else {
                    $f = fopen($fn, "w");
            }
            $this->_tableDataToCsv($def, $f);
            fclose($f);
        }
    }

    protected function _tableDataToCsv($tableName, $f)
    {
        // TODO it may be better if Storage_Mysql whould hangle storing of array data
        $q = $this->_db->query("SELECT * FROM `{$this->_dbName}`.`$tableName`");
        //$q = $this->_db->query("SELECT * FROM `a`.`a_export`");
        while (false!==($data=$q->fetch(PDO::FETCH_NUM))) {
            // we have to convert null fields to \N
            foreach ($data as &$c) {
                if (is_null($c)) {
                    $c = "\N";
                } else {
                    $c = strtr($c, array(
                                     "\\" => "\\\\",
                                     "\t" => "\\\t",
                                     "\n" => "\\\n",
                                     "\r" => "\\\r",
                              ));
                }
            }

            // store data in tab delimited format
            //fputcsv($f, $data, "\t", ' ');
            fwrite($f, implode("\t", $data).chr(13).chr(10));
        }
    }

}
/**
 * Create export file: SELECT * INTO OUTFILE '/tmp/a_export' FROM a_export
 *
 * Load data:
*/
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
/*TRUNCATE TABLE cms_page;
LOAD DATA LOCAL INFILE '/home/k2s/Backups/xtbackupMysql/data/cms_page' INTO TABLE cms_page CHARACTER SET UTF8;
select count(*) from cms_page;*/
