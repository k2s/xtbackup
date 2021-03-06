<?php

class Storage_Mysql_Backup implements Storage_Mysql_IBackup
{
    /**
     * @var PDO
     */
    protected $_db;

    /**
     * Should data files be compressed
     * @var bool|string
     */
    protected $_compressDataFiles = false;

    /**
     * Definition of external script to filter objects
     * @var bool
     */
    protected $_filterExtDef = false;
    /**
     * Should user passwords be stored in backup as hash
     * @var bool
     */
    protected $_withPasswords = false;
    /**
     * @var string
     */
    protected $_serverLocation = 'auto';

    /**
     * Exclude table data from export
     * @var bool
     */
    protected $_noData = false;

    /**
     * @var Output_Interface
     */
    protected $_out;

    protected $_dbName;

    const KIND_DB = "db";
    const KIND_USERS = "users";
    const KIND_GRANTS = 'grants';
    const KIND_FUNCTIONS = "functions";
    const KIND_TABLES = "tables";
    const KIND_DATA = 'data';
    const KIND_INDEXES = 'indexes';
    const KIND_REFS = 'refs';
    const KIND_VIEWS = 'views';
    const KIND_TRIGGERS = 'triggers';
    const KIND_PROCEDURES = 'procedures';
    const KIND_END = 'end';

    /**
     * Backup this DB objects types
     * @var array
     */
    protected $_kindsToBackup = array(
        self::KIND_DB,
        self::KIND_USERS,
        self::KIND_GRANTS,
        self::KIND_FUNCTIONS,
        self::KIND_TABLES,
        self::KIND_DATA,
        self::KIND_INDEXES,
        self::KIND_REFS,
        self::KIND_VIEWS,
        self::KIND_TRIGGERS,
        self::KIND_PROCEDURES,
        self::KIND_END,
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

    function setOutput($out)
    {
        $this->_out = $out;
    }

    function setDataCompression($compressDataFiles)
    {
        $this->_compressDataFiles = $compressDataFiles;
    }

    function setFilterExt($filterExtDefinition)
    {
        $this->_filterExtDef = $filterExtDefinition;
    }

    function setWithPasswords($withPasswords)
    {
        $this->_withPasswords = $withPasswords;
    }

    function setServerLocation($serverLocation)
    {
        $this->_serverLocation = $serverLocation;
    }

    function setNoData($nodata) {
        $this->_noData = $nodata;
    }

    function addRestoreScript($folder)
    {
        $src = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'cliRestore.php';
        $trg = $folder . 'restore.php';
        copy($src, $trg);
    }

    function applyFilterToObjects()
    {
        // filter objects which should be backed up
        if ($this->_filterExtDef) {
            // apply external filter
            foreach ($this->_cachedObjectsToBackup as $kind => &$objects) {
                foreach ($objects as $k => $v) {
                    $r = $this->_filterExt($kind, $v);
                    if (1 !== $r) {
                        if ($r === 2) {
                            $this->_out->logError("$kind: external filter requested stop on '$v'");
                            die;
                        }
                        $this->_out->logNotice("$kind: skip '$v' because of external filter");
                        unset($objects[$k]);
                        continue;
                    }
                }
            }
        }
    }

    protected function _outObject($kind, $name)
    {
        $this->_out->logNotice("backup '$kind' ... '$name'");
    }

    function listAvailableObjectsToBackup($kind = false)
    {
        /*if ($kind==self::KIND_TABLES || $kind==self::KIND_DATA) {
            return array('video');
        }*/

        if ($kind === false) {
            // build list object of all kinds

            $res = array();
            foreach ($this->_kindsToBackup as $kind) {
                $res[$kind] = $this->listAvailableObjectsToBackup($kind);
            }

            return $res;
        }

        // build list of object names of given kind
        if (!isset($this->_cachedObjectsToBackup[$kind])) {
            $funcName = "_cache" . ucfirst($kind);
            if (method_exists($this, $funcName)) {
                $this->$funcName();
            } else {
                $this->_out->logWarning("don't know how to build list of '$kind'.");
                $this->_cachedObjectsToBackup[$kind] = array();
            }

        }

        return $this->_cachedObjectsToBackup[$kind];
    }

    protected function _filterExt($action, $objName, $cmd = null)
    {
        if (is_null($cmd)) {
            $cmd = $this->_filterExtDef;
        }
        if (!$cmd) {
            return 1;
        }

        $cmd .= escapeshellarg($this->_dbName) . ' ' . escapeshellarg($action) . ' '. escapeshellarg($objName);
        $output = array();
        $ret = null;
        exec($cmd, $output, $ret);
        if ($output) {
            $this->_out->logError("Error output from external filter:" . PHP_EOL . implode(PHP_EOL, $output));
        }

        return $ret;
    }

    public function removeObject($kind, $name)
    {
        // TODO allow exclusion of objects from backup
    }

    protected function _cacheDb()
    {
        $this->_cachedObjectsToBackup[self::KIND_DB] = array($this->_dbName);
    }

    protected function _cacheEnd()
    {
        $this->_cachedObjectsToBackup[self::KIND_END] = array();
    }

    function doBackup($store)
    {
        foreach ($this->_kindsToBackup as $kind) {
            $funcName = "_backup" . ucfirst($kind);
            if (method_exists($this, $funcName)) {
                $this->$funcName($store);
            } else {
                $this->_out->logWarning("don't know how to backup objects of type '$kind'");
            }
        }
    }

    /**
     * @param Storage_Mysql_IStore $store
     * @return void
     */
    protected function _backupEnd($store)
    {
        $def = $this->_db->query("SELECT UTC_TIMESTAMP()")->fetchColumn(0);
        $def = strtotime($def . "UTC");
        $def = date("c", $def);
        $phpTime = date("c");
        $store->storeDbObject(self::KIND_DB, "_timeend", <<<TXT
script: $phpTime
mysql: $def
TXT
        );

    }


    /**
     * @param Storage_Mysql_IStore $store
     * @return void
     */
    protected function _backupDb($store)
    {
        $this->_out->logNotice("script database ... '{$this->_dbName}'");
        // script DB creation
        $def = $this->_db->query("show create DATABASE `{$this->_dbName}`;")->fetchColumn(1);
        $def = substr($def, 0, 16) . 'IF NOT EXISTS ' . substr($def, 16);
        $store->storeDbObject(self::KIND_DB, "_name", $this->_dbName);
        $store->storeDbObject(self::KIND_DB, "_create", $def);
        $def = $this->_db->query("SELECT UTC_TIMESTAMP()")->fetchColumn(0);
        $def = strtotime($def . "UTC");
        $def = date("c", $def);
        $phpTime = date("c");
        $store->storeDbObject(self::KIND_DB, "_timestart", <<<TXT
script: $phpTime
mysql: $def
TXT
        );

    }

    protected function _backupRefs($store)
    {
    }

    protected function _backupIndexes($store)
    {
    }

    protected function _usersAndGrants($store)
    {
        if (in_array(self::KIND_GRANTS, $this->_kindsToBackup)) {
            $this->_out->logNotice("script users and grants ... '{$this->_dbName}'");
        } else {
            $this->_out->logNotice("script users without grants ... '{$this->_dbName}'");
        }

//        var_dump($this->_parseGrant("GRANT ALL PRIVILEGES ON `tine20`.* TO 'tine20'@'localhost'"));
//        die;

        $userList = array();

        $fn = $store->storeFilenameFor(self::KIND_USERS, "grants");
        $f = fopen($fn, "w");
        foreach ($this->_cachedObjectsToBackup[self::KIND_USERS] as $user) {
            $q = $this->_db->query("SHOW GRANTS FOR $user");
            foreach ($q->fetchAll(PDO::FETCH_NUM) as $o) {
                $p = $this->_parseGrant($o[0]);
                if ($p['db'] === "*" || $p['db'] === $this->_dbName) {
                    // add user to unique list for user creation
                    if (!array_key_exists($p['to'], $userList)) {
                        $userList[$p['to']] = $p['to'] . ":";
                    }
                    // remember password if wanted
                    if ($this->_withPasswords && array_key_exists('password', $p)) {
                        $userList[$p['to']] .= $p['password'];
                    }
                    unset($p['password']);
                    // store grant privileges
                    fputcsv($f, $p);
                }
            }
        }
        fclose($f);
        $store->storeDbObject(self::KIND_USERS, "users", implode("\n", $userList));
    }

    protected function _isKeyword($r, $keywords, &$keyword, &$keywordLen)
    {
        $found = false;
        foreach ($keywords as $keyword) {

            $keywordLen = strlen($keyword);
            if ((strncasecmp($r, $keyword, $keywordLen) === 0) && ((strlen($r) === $keywordLen) || ctype_space($r[$keywordLen]))) {
                $found = true;
                break;
            }
        }

        return $found;
    }

    protected function _parseGrant($r)
    {
        $original = $r;
        $ret = array();
        $data = "";
        $f = array(array("GRANT"), array("ON"), array("TO"), array("IDENTIFIED", "WITH"));
        while (count($f)) {
            $keywords = array_shift($f);
            $r = ltrim($r);
            while (0 < strlen($r)) {
                if ($this->_isKeyword($r, $keywords, $keyword, $keywordLen)) {
                    $data = trim($data);
                    switch ($keyword) {
                        case 'GRANT':
                            break;
                        case 'ON':
                            $ret['grant'] = $data;
                            break;
                        case 'TO':
                            if (preg_match("/`?([^\.`]*)`?(\..*)/", $data, $a)) {
                                $ret['db'] = $a[1];
                                $ret['on'] = $a[2];
                            } else {
                                $this->_out->logCritical("Can't parse ON parameter '$data' in: $original");
                            }
                            break;
                        default:
                            $ret['to'] = $data;
                            $ret['other'] = trim($r);
                            $r = "";
                    }
                    $r = substr($r, $keywordLen + 1);
                    $data = "";
                    break;
                }
                $data .= $r[0];
                $r = substr($r, 1);
            }
        }
        if (!array_key_exists('to', $ret)) {
            $ret['to'] = $data;
            $ret['other'] = trim($r);
        }

        // handle password in other
        if (preg_match("/IDENTIFIED BY (PASSWORD)? '(.*)'/", $ret['other'], $a)) {
            $ret['password'] = $a[2];
            $ret['other'] = trim(preg_replace("/IDENTIFIED BY (PASSWORD)? '.*'/", '', $ret['other']));
        }

        return $ret;
    }

    protected function _backupUsers($store)
    {
        $this->_usersAndGrants($store);
    }

    protected function _backupGrants($store)
    {
        if (!in_array(self::KIND_USERS, $this->_kindsToBackup)) {
            $this->_usersAndGrants($store);
        }
    }

    /**
     * @param Storage_Mysql_IStore $store
     * @param string $kind
     * @param string $cmd
     * @param int $colIndex
     */
    protected function _helperBackupCodeObject($store, $kind, $cmd, $colIndex)
    {
        foreach ($this->listAvailableObjectsToBackup($kind) as $def) {
            if (is_array($def)) {
                // we store multiple informations about triggers
                $def = $def[0];
            }
            $this->_outObject($kind, $def);
            $sql = $this->_db->query($cmd . " `{$this->_dbName}`.`$def`")->fetchColumn($colIndex);
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

    /**
     * @param Storage_Mysql_IStore $store
     */
    protected function _backupTriggers($store)
    {
        $this->_helperBackupCodeObject($store, self::KIND_TRIGGERS, "SHOW CREATE TRIGGER", 2);
    }

    /**
     * @param Storage_Mysql_IStore $store
     */
    protected function _backupTables($store)
    {
        foreach ($this->listAvailableObjectsToBackup(self::KIND_TABLES) as $def) {
            // info SHOW TABLE STATUS LIKE 'TABLES';
            $this->_outObject(self::KIND_TABLES, $def);

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
                        0 == strncasecmp($t, "CONSTRAINT ", 11)
                    ) {
                        // constraint found
                        $refs[] = "ADD " . $t;
                    } else if (
                        0 == strncasecmp($t, "KEY ", 4) ||
                        0 == strncasecmp($t, "UNIQUE KEY ", 11) ||
                        0 == strncasecmp($t, "PRIMARY KEY ", 12)
                    ) {
                        // index found
                        $idx[] = "ADD " . $t;
                    } elseif ($t[0] == ")") {
                        // end of column definition
                        $last = rtrim($last, ",");
                        unset($last);
                        $tbl[] = & $t;
                    } else {
                        // table definition
                        if ($autoIncrementField && 0 === strncasecmp("`$autoIncrementField`", $t, 2 + strlen($autoIncrementField))) {
                            // autoincrement field

                            // add to indexes
                            $idx[] = "MODIFY " . rtrim($t, ',') . ',';

                            // remove definition
                            // TODO safe would be to make sure, that it is not part of field name or COMMENT
                            $t = str_replace(" AUTO_INCREMENT", "", $t);
                        }
                        unset($last);
                        $last = $t;
                        $tbl[] = & $last;
                    }
                }

                // store the table
                $tbl = implode(PHP_EOL, $tbl);

                $store->storeDbObject(self::KIND_TABLES, $def, $tbl . ';');

                // store tables indexes
                if (count($idx)) {
                    array_unshift($idx, "ALTER TABLE `$def`");
                    $idx[count($idx) - 1] = rtrim($idx[count($idx) - 1], ',');
                    $idx = implode(PHP_EOL, $idx);
                    $store->storeDbObject(self::KIND_INDEXES, $def, $idx . ';');
                }

                // store table constraints
                if (count($refs)) {
                    array_unshift($refs, "ALTER TABLE `$def`");
                    $refs = implode(PHP_EOL, $refs);
                    $store->storeDbObject(self::KIND_REFS, $def, $refs . ';');
                }
            }
        }
    }

    /**
     * @param string $kind
     * @param string $sql
     * @return string
     */
    protected function _removeSecurity(/** @noinspection PhpUnusedParameterInspection */
        $kind, &$sql)
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
        $l = 0;
        // this loop is here, because it may be good to extract also SQL SECURITY INVOKER attribute
        foreach ($a as $s) {
            if (++$l == 1) {
                // search for DEFINER
                if (preg_match("/DEFINER=`([^`]+)`@`([^`]+)`/i", $s, $m, PREG_OFFSET_CAPTURE) > 0) {
                    $security = $m[0][0];
                    $s = substr($s, 0, $m[0][1] - 1) . substr($s, $m[0][1] + strlen($m[0][0]));
                }
            }
            $b[] = $s;
        }

        $sql = implode("\n", $b);

        return $security;
    }

    protected function _backupData($store)
    {
        switch ($this->_serverLocation) {
            case 'local':
                $this->_out->logNotice("doing 'local' data backup");
                $this->_backupDataFromLocal($store);
                break;
            case 'remote':
                $this->_out->logNotice("doing 'remote' data backup");
                $this->_backupDataFromRemote($store);
                break;
            default:
                // try to detect best possibility
                $s = $this->_db->getAttribute(PDO::ATTR_CONNECTION_STATUS);
                $this->_out->logNotice("connection status: $s");
                if (stripos($s, "localhost") === false && stripos($s, "127.0.0.1") === false) {
                    // remote
                    $this->_out->logNotice("detected 'remote' data backup");
                    $this->_backupDataFromRemote($store);
                } else {
                    // maybe local will work
                    try {
                        $this->_out->logNotice("trying 'local' data backup");
                        $this->_backupDataFromLocal($store);
                    } catch (Exception $ex) {
                        $this->_out->logNotice("fallback to 'remote' data backup");
                        $this->_backupDataFromRemote($store);
                    }
                }
        }
    }

    /**
     * @param Storage_Mysql_IStore $store
     */
    protected function _backupDataFromLocal($store)
    {
        foreach ($this->listAvailableObjectsToBackup(self::KIND_DATA) as $def) {
            $this->_outObject(self::KIND_DATA, $def);
            $fn = $store->storeFilenameFor(self::KIND_DATA, $def);

            $sql = <<<SQL
SELECT * INTO OUTFILE "$fn"
  FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"'
  LINES TERMINATED BY "\\n"
  FROM `{$this->_dbName}`.`$def`;
SQL;
            $this->_db->query($sql);

            // TODO compress if configured
        }
    }

    /**
     * @param Storage_Mysql_IStore $store
     */
    protected function _backupDataFromRemote($store)
    {
        foreach ($this->listAvailableObjectsToBackup(self::KIND_DATA) as $def) {
            $this->_outObject(self::KIND_DATA, $def);
            $fn = $store->storeFilenameFor(self::KIND_DATA, $def);
            if ($this->_compressDataFiles) {
                if ($this->_compressDataFiles === "gzip") {
                    $f = popen("gzip - -c > " . escapeshellcmd($fn . ".z"), "w");
                } else {
                    $f = fopen("compress.zlib://" . $fn . ".z", "w");
                }
            } else {
                $f = fopen($fn, "w");
            }
            // TODO handle error opening file
            $this->_tableDataToCsv($def, $f);
            if ($this->_compressDataFiles === "gzip") {
                pclose($f);
            } else {
                fclose($f);
            }
        }
    }

    protected function _tableDataToCsv($tableName, $f)
    {
        // TODO it may be better if Storage_Mysql whould hangle storing of array data
        $q = $this->_db->query("SELECT * FROM `{$this->_dbName}`.`$tableName`");
        //$q = $this->_db->query("SELECT * FROM `a`.`a_export`");
        while (false !== ($data = $q->fetch(PDO::FETCH_NUM))) {
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
            fwrite($f, implode("\t", $data) . chr(10));
        }
    }

}

///**
// * Create export file: SELECT * INTO OUTFILE '/tmp/a_export' FROM a_export
// *
// * Load data:
// */
// /*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
// /*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
// /*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
// /*!40101 SET NAMES utf8 */;
// /*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
// /*!40103 SET TIME_ZONE='+00:00' */;
// /*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
// /*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
// /*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
// /*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
// /*TRUNCATE TABLE cms_page;
// LOAD DATA LOCAL INFILE '/home/k2s/Backups/xtbackupMysql/data/cms_page' INTO TABLE cms_page CHARACTER SET UTF8;
// select count(*) from cms_page;
//*/