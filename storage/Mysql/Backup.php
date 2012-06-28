<?php
class Storage_Mysql_Backup implements Storage_Mysql_IBackup
{
    /**
     * @var PDO
     */
    protected $_db;

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

    function listAvailableObjectsToBackup($kind=false)
    {
        /*if ($kind==self::KIND_TABLES) {
            return array('_as_linkmanager_links');
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

    protected function _prepareBackup()
    {

    }

    function doBackup($storeCallback)
    {
        // make sure we already prepared backup
        $this->_prepareBackup();

        foreach ($this->_kindsToBackup as $kind) {
            $funcName = "_backup".ucfirst($kind);
            if (method_exists($this, $funcName)) {
                $this->$funcName($storeCallback);
            } else {
                echo "don't know of to backup objects of type '$kind'.\n";
            }
        }
    }
    function _backupRefs($storeCallback) {}
    function _backupIndexes($storeCallback) {}

    function _backupTables($storeCallback)
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
                call_user_func($storeCallback, self::KIND_TABLES, $def, $tbl.';');

                // store tables indexes
                if (count($idx)) {
                    array_unshift($idx, "ALTER TABLE `$def`");
                    $idx[count($idx)-1] = rtrim($idx[count($idx)-1], ',');
                    $idx = implode(PHP_EOL, $idx);
                    call_user_func($storeCallback, self::KIND_INDEXES, $def, $idx.';');
                }

                // store table constraints
                if (count($refs)) {
                    array_unshift($refs, "ALTER TABLE `$def`");
                    $refs = implode(PHP_EOL, $refs);
                    call_user_func($storeCallback, self::KIND_REFS, $def, $refs.';');
                }
            }
        }
    }

    function _backupData($storeCallback)
    {
        // TODO detect if server is localhost
        if (false) {
            $this->_backupDataFromLocal($storeCallback);
        } else {
            $this->_backupDataFromRemote($storeCallback);
        }
    }

    function _backupDataFromLocal($storeCallback)
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

    function _backupDataFromRemote($storeCallback)
    {
        foreach ($this->listAvailableObjectsToBackup(self::KIND_DATA) as $def) {

        }
    }
}
