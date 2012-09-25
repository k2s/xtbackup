<?php
class Storage_Mysql_Backup5x0x2 extends Storage_Mysql_Backup
{
    /**
     * Create list of tables, views and data objects
     * @return void
     */
    protected function _cacheTables()
    {
        $tables = $views = array();
        $q = $this->_db->query("SHOW FULL TABLES");
        foreach ($q->fetchAll(PDO::FETCH_NUM) as $o) {
            if ($o[1]=="VIEW") {
                $views[] = $o[0];
            } else {
                $tables[] = $o[0];
            }
        }

        $this->_cachedObjectsToBackup[self::KIND_VIEWS] = $views;
        $this->_cachedObjectsToBackup[self::KIND_TABLES] = $tables;
        $this->_cachedObjectsToBackup[self::KIND_DATA] = $tables;
        $this->_cachedObjectsToBackup[self::KIND_INDEXES] = &$this->_cachedObjectsToBackup['tables'];
        $this->_cachedObjectsToBackup[self::KIND_REFS] = &$this->_cachedObjectsToBackup['tables'];
    }

    protected function _cacheIndexes()
    {
        // this is handled already in _cacheTables()
        $this->_cacheTables();
    }
    protected function _cacheViews()
    {
        // this is handled already in _cacheTables()
        $this->_cacheTables();
    }
    protected function _cacheRefs()
    {
        // this is handled already in _cacheTables()
        $this->_cacheTables();
    }
    protected function _cacheTriggers()
    {
        $sql = <<<SQL
select trigger_name, event_object_table, event_object_schema from INFORMATION_SCHEMA.`TRIGGERS` where trigger_schema="{$this->_dbName}"
SQL;
        $q = $this->_db->query($sql);
        $this->_cachedObjectsToBackup[self::KIND_TRIGGERS] = $q->fetchAll(PDO::FETCH_NUM);
    }

    protected function _cacheFunctions()
    {
        $sql = <<<SQL
select ROUTINE_NAME from INFORMATION_SCHEMA.ROUTINES where routine_schema="{$this->_dbName}" and ROUTINE_TYPE="FUNCTION"
SQL;
        $q = $this->_db->query($sql);
        $this->_cachedObjectsToBackup[self::KIND_FUNCTIONS] = $q->fetchAll(PDO::FETCH_COLUMN);
    }

    protected function _cacheProcedures()
    {
        $sql = <<<SQL
select ROUTINE_NAME from INFORMATION_SCHEMA.ROUTINES where routine_schema="{$this->_dbName}" and ROUTINE_TYPE="PROCEDURE"
SQL;
        $q = $this->_db->query($sql);
        $this->_cachedObjectsToBackup[self::KIND_PROCEDURES] = $q->fetchAll(PDO::FETCH_COLUMN);
    }

    protected function _cacheUsers()
    {
/*
mysql -BNe "select concat('\'',user,'\'@\'',host,'\'') from mysql.user where user != 'root'" | \
while read uh; do mysql -BNe "show grants for $uh" | sed 's/$/;/; s/\\\\/\\/g'; done > grants.sql

mysql -B -N $@ -e "SELECT DISTINCT CONCAT(
    'SHOW GRANTS FOR ''', user, '''@''', host, ''';'
    ) AS query FROM mysql.user" | \
  mysql $@ | \
  sed 's/\(GRANT .*\)/\1;/;s/^\(Grants for .*\)/## \1 ##/;/##/{x;p;x;}'

select * from mysql.user;
 */
        $this->_cachedObjectsToBackup[self::KIND_USERS] = array();
        // http://serverfault.com/questions/8860/how-can-i-export-the-privileges-from-mysql-and-then-import-to-a-new-server/13050#13050
    }

    protected function _cacheGrants()
    {
        $this->_cachedObjectsToBackup[self::KIND_GRANTS] = array();
    }
}
