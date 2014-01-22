<?php

class Compare_Sqlite implements Compare_Interface, Iterator
{
    /**
     *
     * @var Core_Engine
     */
    protected $_engine;
    /**
     * @var Output_Stack
     */
    protected $_out;
    /**
     *
     * @var array
     */
    protected $_options;
    /**
     *
     * @var boolean
     */
    protected $_testing = false;

    /**
     *
     * @var SQLite3
     */
    protected $_db;

    /**
     * Prefix used for storage
     *
     * Needed to be able to use same DB for multiple different backups
     *
     * @var string
     */
    protected $_prefix;
    /** @var int */
    protected $_jobPosition = 0;
    /**
     * @var SQLite3Result
     */
    protected $_jobSqlResult;
    /** @var array */
    protected $_job;

    /** @var SQLite3Stmt */
    protected $_prepRemoteHasDeleted;
    /** @var SQLite3Stmt */
    protected $_prepRemoteHasUploaded;
    /** @var SQLite3Stmt */
    protected $_prepFromLocal1;
    /** @var SQLite3Stmt */
    protected $_prepFromLocal2;
    /** @var SQLite3Stmt */
    protected $_prepFromLocalFull1;
    /** @var SQLite3Stmt */
    protected $_prepFromLocalFull2;
    /** @var SQLite3Stmt */
    protected $_prepFromRemote2;
    /** @var SQLite3Stmt */
    protected $_prepFromRemote1;
    /** @var SQLite3Stmt */
    protected $_prepFromLocalMd5;
    /** @var SQLite3Stmt */
    protected $_prepFromRemoteMd5;

    /**
     * @param Core_Engine $engine
     * @param Output_Stack $output
     * @param array $options
     */
    public function  __construct($identity, $engine, $output, $options)
    {
        // merge options with default options
        Core_Engine::array_merge_defaults(
            $options,
            static::getConfigOptions(CfgPart::DEFAULTS),
            static::getConfigOptions(CfgPart::HINTS)
        );

        $options['prefix'] = false;
        //$options['keep'] = false;
        $this->_out = $output;
        $this->_options = $options;
        $this->_engine = $engine;
        $this->_testing = $this->_options['testing']; // for faster access

        // test if SQLite3 extension is loaded
        if (!extension_loaded("sqlite3")) {
            $this->_out->stop("PHP extension sqlite3 (http://www.php.net/manual/en/book.sqlite3.php) has to be loaded.");
        }
    }

    public function init($myrole, $drivers)
    {
        $this->_out->logNotice(">>>init " . get_class($this) . " compare driver");

        $this->_out->logDebug("opening DB file: " . $this->_options['file']);
        $this->_open();

        if ($this->_options['prefix']) {
            $this->_prefix = $this->_options['prefix'];
        } else {
            $this->_prefix = $this->_engine->getUniqueKey();
        }
        $this->_out->logDebug("we will use table name prefix: '$this->_prefix'");

        // TODO check that sqlite 3 is installed
        // TODO check mandatory options
        // TODO complain about not allowed options
        // TODO $options['prefix'] can't start with _ it would interfear with testing mode

        if ($this->_testing) {
            // TODO maybe using transaction ROLLBACK in shutdown is better
            $this->_prefix = "_" . $this->_prefix;
            $this->_out->logDebug("we are in testing mode so prefix is changed to: '$this->_prefix'");
        }

        $this->_createStructure($this->_options['rebuild']);
        $this->_dbIndexOn(false);

        // prepared statements
        $this->_prepFromRemote2 = $this->_db->prepare($this->_sql(
            "INSERT INTO {$this->_prefix} (path, isDir, remote, rmd5, rtime, rsize) VALUES(:path, :isDir, :isRemote, :md5, :time, :size)", "prepare SQL: "
        ));
        $this->_prepFromRemote1 = $this->_db->prepare($this->_sql(
            "UPDATE {$this->_prefix} SET isDir=:isDir, remote=:isRemote, rsize=:size, rmd5=:md5, rtime=:time WHERE path=:path", "prepare SQL: "
        ));
        $this->_prepFromLocalFull2 = $this->_db->prepare($this->_sql(
            "INSERT INTO {$this->_prefix} (path, isDir, local, lmd5, ltime, lsize) VALUES(:path, :isDir, :isLocal, :md5, :time, :size)", "prepare SQL: "
        ));
        $this->_prepFromLocalFull1 = $this->_db->prepare($this->_sql(
            "UPDATE {$this->_prefix} SET isDir=:isDir, local=:isLocal, lsize=:size, lmd5=:md5, ltime=:time WHERE path=:path", "prepare SQL: "
        ));
        $this->_prepFromLocal2 = $this->_db->prepare($this->_sql(
            "INSERT INTO {$this->_prefix} (path, isDir, local, ltime, lsize) VALUES(:path, :isDir, :isLocal, :time, :size)", "prepare SQL: "
        ));
        $this->_prepFromLocal1 = $this->_db->prepare($this->_sql(
            "UPDATE {$this->_prefix} SET isDir=:isDir, local=:isLocal, lsize=:size, ltime=:time WHERE path=:path", "prepare SQL: "
        ));

        $this->_prepFromLocalMd5 = $this->_db->prepare($this->_sql(
            "UPDATE {$this->_prefix} SET lmd5=:md5 WHERE path=:path", "prepare SQL: "
        ));
        $this->_prepFromRemoteMd5 = $this->_db->prepare($this->_sql(
            "UPDATE {$this->_prefix} SET rmd5=:md5, rts=CASE WHEN rts is null THEN ltime ELSE rts END WHERE path=:path", "prepare SQL: "
        ));

        // remote has done updates
        $this->_prepRemoteHasDeleted = $this->_db->prepare($this->_sql(
            "DELETE FROM {$this->_prefix} WHERE path=:path", "prepare SQL: "
        ));
        $this->_prepRemoteHasUploaded = $this->_db->prepare($this->_sql(
            "UPDATE {$this->_prefix} SET remote=1, rmd5=lmd5, rsize=lsize, rts=ltime WHERE path=:path", "prepare SQL: "
        ));
    }

    function wasAlreadyUpdatedFrom($role)
    {
        switch ($role) {
            case Core_Engine::ROLE_LOCAL:
                $role = 'local';
                break;
            case Core_Engine::ROLE_REMOTE:
                $role = 'remote';
                break;
            default:
                throw new Exception("wrong $role parameter");
        }

        $res = $this->_db->querySingle("SELECT count(*) FROM {$this->_prefix} WHERE {$role}");
        if (false === $res) {
            throw new Exception("problem in query execution");
        }
        return $res == 0 ? false : true;
    }

    public function shutdown($myrole, $drivers)
    {
        $this->_out->logNotice(">>>shutdown Sqlite compare driver");
        if ($this->_testing && !$this->_options['keep']) {
            $this->_exec("DROP TABLE IF EXISTS " . $this->_prefix);
        }
        $this->_exec("COMMIT TRANSACTION");
    }

    protected function _open()
    {
        $ver = SQLite3::version();
        $this->_out->logDebug("SQLite version: $ver[versionString]");
        $this->_db = new SQLite3($this->_options['file']);
        $this->_exec("BEGIN TRANSACTION");
    }

    protected function _createStructure($force)
    {
        if ($force) {
            $this->_out->logDebug("rebuilding table because requested");
            $this->_exec("DROP TABLE IF EXISTS " . $this->_prefix);

        }
        $this->_exec(
            "CREATE TABLE IF NOT EXISTS {$this->_prefix} " .
            "(path TEXT, isdir INTEGER, local INTEGER NOT NULL DEFAULT 0, remote INTEGER NOT NULL DEFAULT 0, lmd5 TEXT, rmd5 TEXT, ltime INTEGER, rtime INTEGER, rts INTEGER, lsize, rsize, PRIMARY KEY(path ASC))"
        );
    }

    protected function _dbIndexOn($on)
    {
        if ($on) {
            // add needed indexes
            // CREATE INDEX IDX_isdir ON {$this->_prefix}(isdir  ASC);
        } else {
            // drop indexes
            //$this->_exec("DROP INDEX IF EXISTS IDX_isdir");
        }
    }

    protected function _sql($sql, $prefix = "exec sql: ")
    {
        $this->_out->logDebug($prefix . $sql);
        return $sql;
    }

    protected function _exec($sql)
    {
        if (!$this->_db->exec($this->_sql($sql))) {
            // TODO send error message to out
            $this->_out->stop("Sqlite exec error");
        };
    }

    public function updateFromRemoteStart()
    {
        $this->_exec("SAVEPOINT fromremote");
        $this->_exec("UPDATE {$this->_prefix} SET remote=0");
    }

    public function updateFromRemoteEnd()
    {
        $this->_exec("RELEASE SAVEPOINT fromremote");
    }

    /**
     * Update compare database with data from remote storage
     *
     * @param $fsObject
     *
     * @return void
     */
    public function updateFromRemote($fsObject)
    {
        $this->_prepFromRemote1->bindValue(":isDir", $fsObject->isDir);
        $this->_prepFromRemote1->bindValue(":path", $fsObject->path);
        $this->_prepFromRemote1->bindValue(":isRemote", 1);
        $this->_prepFromRemote1->bindValue(":md5", $fsObject->md5);
        $this->_prepFromRemote1->bindValue(":size", $fsObject->size);
        $this->_prepFromRemote1->bindValue(":time", $fsObject->time);
        $this->_prepFromRemote1->execute();
        if ($this->_db->changes() == 0) {
            // record doesn't exists
            $this->_prepFromRemote2->bindValue(":isDir", $fsObject->isDir);
            $this->_prepFromRemote2->bindValue(":path", $fsObject->path);
            $this->_prepFromRemote2->bindValue(":isRemote", 1);
            $this->_prepFromRemote2->bindValue(":md5", $fsObject->md5);
            $this->_prepFromRemote2->bindValue(":size", $fsObject->size);
            $this->_prepFromRemote2->bindValue(":time", $fsObject->time);
            $this->_prepFromRemote2->execute();
        }
    }

    public function updateFromLocalStart()
    {
        $this->_exec("SAVEPOINT fromlocal");
        $this->_exec("UPDATE {$this->_prefix} SET local=0, lmd5=null");
    }

    public function updateFromLocalEnd()
    {
        $this->_exec("RELEASE SAVEPOINT fromlocal");
    }

    /**
     * Update compare database with data from local storage
     *
     * @param Core_FsObject $fsObject file system object
     *
     * @return void
     */
    public function updateFromLocal($fsObject)
    {
        if (false === $fsObject->md5) {
            $prep1 = $this->_prepFromLocal1;
            $prep2 = $this->_prepFromLocal2;
        } else {
            $prep1 = $this->_prepFromLocalFull1;
            $prep1->bindValue(":md5", $fsObject->md5);
            $prep2 = $this->_prepFromLocalFull2;
            $prep2->bindValue(":md5", $fsObject->md5);
        }
        $prep1->bindValue(":isDir", $fsObject->isDir);
        $prep1->bindValue(":path", $fsObject->path);
        $prep1->bindValue(":isLocal", 1);
        $prep1->bindValue(":size", $fsObject->size);
        $prep1->bindValue(":time", $fsObject->time);
        $prep1->execute();
        if ($this->_db->changes() == 0) {
            // record doesn't exists
            $prep2->bindValue(":isDir", $fsObject->isDir);
            $prep2->bindValue(":path", $fsObject->path);
            $prep2->bindValue(":isLocal", 1);
            $prep2->bindValue(":size", $fsObject->size);
            $prep2->bindValue(":time", $fsObject->time);
            $prep2->execute();
        }
    }

    public function compare($myrole, $drivers)
    {
        $this->_out->logNotice(">>>compare with Sqlite compare driver");

        // creating indexes
        $this->_dbIndexOn(true);

        // delete invalid rows
        $this->_exec("DELETE FROM {$this->_prefix} WHERE local=0 and remote=0");

        // update missing md5 for remote files where needed
        $this->_out->logNotice(">>>starting update md5 of remote files ...");
        $resCount = $this->_db->querySingle($this->_sql(<<<SQL
        SELECT count(*) FROM {$this->_prefix} WHERE isdir=0 and local and remote and rmd5 is null and (rts<>ltime or rts is null)
SQL
        ));
        $stmt = $this->_db->query($this->_sql(<<<SQL
SELECT path FROM {$this->_prefix} WHERE isdir=0 and local and remote and rmd5 is null and (rts<>ltime or rts is null)
SQL
        ));
        /** @var $driver Storage_Interface */
        $driver = $drivers['remote'];
        $counter = 0;
        $job = $this->_out->jobStart("we need to calculate md5 for $resCount remote files");
        $this->_out->jobSetProgressStep($job, 50);
        while ($row = $stmt->fetchArray()) {
            $fullPath = $driver->getBaseDir() . $row['path'];
            $this->_out->logDebug($fullPath);

            if ($md5 = $driver->getMd5($fullPath)) {
                $this->_prepFromRemoteMd5->bindValue(":md5", $md5);
                $this->_prepFromRemoteMd5->bindValue(":path", $row['path']);
                $this->_prepFromRemoteMd5->execute();

                $this->_out->jobStep($job);
                $counter++;
            }
        }
        $this->_out->jobEnd($job, "md5 calculated for $counter files");


        // update missing md5 for local files where needed
        $this->_out->logNotice(">>>starting update md5 of local files ...");
        $resCount = $this->_db->querySingle($this->_sql(<<<SQL
SELECT count(*) FROM {$this->_prefix} WHERE isdir=0 and local and remote and (rts<>ltime or rts is null)
SQL
        ));
        $stmt = $this->_db->query($this->_sql(<<<SQL
SELECT path FROM {$this->_prefix} WHERE isdir=0 and local and remote and (rts<>ltime or rts is null)
SQL
        ));

        $driver = $drivers['local'];
        $counter = 0;
        $job = $this->_out->jobStart("we need to calculate md5 for $resCount local files");
        $this->_out->jobSetProgressStep($job, 50);
        while ($row = $stmt->fetchArray()) {
            $fullPath = $driver->getBaseDir() . $row['path'];
            $this->_out->logDebug($fullPath);

            if ($md5 = $driver->getMd5($fullPath)) {
                $this->_prepFromLocalMd5->bindValue(":md5", $md5);
                $this->_prepFromLocalMd5->bindValue(":path", $row['path']);
                $this->_prepFromLocalMd5->execute();

                $this->_out->jobStep($job);
                $counter++;
            }
        }
        $this->_out->jobEnd($job, "md5 calculated for $counter files");
    }

    /**
     * Initialize iterator of tasks to be executed on storage
     *
     * @param int $storageType type of storage (Core_Engine::ROLE_REMOTE or Core_Engine::ROLE_LOCAL)
     * @return bool
     */
    public function initChangesOn($storageType)
    {
        switch ($storageType) {
            case Core_Engine::ROLE_LOCAL:
                // we don't care at this moment
                break;
            case Core_Engine::ROLE_REMOTE:
                // TODO on this place there should be SQL using union which will be executed against SQL
                // and its results will be returned on each Iterator::next()
                // the $storageType defines if we want to receive changes for local or remote storage
                $cmdDelete = self::CMD_DELETE;
                $cmdMkdir = self::CMD_MKDIR;
                $cmdTs = self::CMD_TS;
                $cmdPut = self::CMD_PUT;
                $sql = <<<SQL
SELECT path, ltime, '$cmdDelete' as action FROM {$this->_prefix} WHERE remote and local=0
UNION
SELECT path, ltime, '$cmdMkdir' as action FROM {$this->_prefix} WHERE isdir=1 and local and remote=0
UNION
SELECT path, ltime, '$cmdPut' as action FROM {$this->_prefix} WHERE isdir<>1 and local and (remote=0 or rsize<>lsize or rmd5!=lmd5)
UNION
SELECT path, ltime, '$cmdTs' as action FROM {$this->_prefix} WHERE isdir<>1 and local and remote and rmd5=lmd5 and (rts is null or rts<>ltime)
SQL;
                $this->_jobSqlResult = $this->_db->query($sql);
                break;
            default:
                throw new Exception("wrong $storageType parameter");
        }


    }

    public function next()
    {
        ++$this->_jobPosition;
    }

    public function rewind()
    {
        $this->_jobPosition = 0;
    }

    public function key()
    {
        return $this->_jobPosition;
    }

    public function current()
    {
        return new Compare_Task($this->_job);
    }

    public function remoteHasDone($task)
    {
        switch ($task->action) {
            case self::CMD_PUT:
                $this->_prepRemoteHasUploaded->bindValue(":path", $task->path);
                $this->_prepRemoteHasUploaded->execute();
                break;
            case self::CMD_DELETE:
                $this->_prepRemoteHasDeleted->bindValue(":path", $task->path);
                $this->_prepRemoteHasDeleted->execute();
                break;
            case self::CMD_MKDIR:
                $this->_prepRemoteHasUploaded->bindValue(":path", $task->path);
                $this->_prepRemoteHasUploaded->execute();
                break;
            case self::CMD_TS:
                $this->_prepRemoteHasUploaded->bindValue(":path", $task->path);
                $this->_prepRemoteHasUploaded->execute();
                break;
            default:
                $this->_out->logError("ignored command {$task->action}");
        }
    }

    public function valid()
    {
        $this->_job = $this->_jobSqlResult->fetchArray(SQLITE3_ASSOC);
        if ($this->_job) {
            return true;
        } else {
            return false;
        }
    }

    //only for testing
    public function tst($value)
    {
        $this->_exec(
            "CREATE TABLE IF NOT EXISTS PathTest " .
            "(path TEXT)"
        );
        $this->_out->logDebug(">>>VALUE must be inserted {$value}");

        $this->_exec("INSERT INTO PathTest VALUES (\"{$value}\")");


    }

    static public function getConfigOptions($part = null)
    {
        $opt = array(
            CfgPart::HINTS => array(
                'testing' => array(CfgPart::HINT_TYPE => CfgPart::TYPE_BOOL),
                'rebuild' => array(CfgPart::HINT_TYPE => CfgPart::TYPE_BOOL),
                'keep' => array(CfgPart::HINT_TYPE => CfgPart::TYPE_BOOL),
                'compare' => array(CfgPart::HINT_TYPE => CfgPart::TYPE_BOOL),
                'file' => array(CfgPart::HINT_TYPE => CfgPart::TYPE_PATH)
            ),
            CfgPart::DEFAULTS => array(
                'testing' => false,
                'rebuild' => false,
                'keep' => false,
                'compare' => true,
                //'file'=>,

            ),
            CfgPart::DESCRIPTIONS => array(
                'testing' => 'stored compare data will not be changed when finished',
                'rebuild' => 'reset all data in table',
                'keep' => "don't drop testing table from DB if testing=true",
                'compare' => 'should compare be executed ?',
                'file' => 'path and file name of sqlite database file to use'
            ),
            CfgPart::REQUIRED => array('file' => true),
            CfgPart::SUGGESTED => array('testing' => true, 'keep' => true, 'rebuild' => true),
        );

        if (is_null($part)) {
            return $opt;
        } else {
            return $opt[$part];
        }
    }
}