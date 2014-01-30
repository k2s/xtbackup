---
layout: default
title: xtBackup Features
---
Features
--------

* ready to be used as command line tool (provided by `xtbackup.php` tool)
* no installation needed, works everywhere where PHP 5.3 and above is installed
  * [DreamHost](http://www.dreamhost.com/) tested
* designed as re-usable PHP code library
* configurable with single/multiple INI file(s) and command line options
* efficient compare algorithm
  * works with huge amount of files
  * minimize traffic and CPU
  * use time and md5 for change detection
  * sqlite3 implementation
* backup from Linux/Windows file system
  * support for UTF8 file names
  * support for case-sensitive file names
  * symlink handling
* backup to Amazon S3 storage
  * parallel upload multiple parts of huge files
  * multiple backups into the same bucket
  * on 32bit architecture, current implementation of the PHP-AWS library doesn't support files over 2GB
* configurable independent output drivers
  * output to console
  * log into sqlite database
* extendable with PHP code
  * file filters
  * new storage drivers
  * backup preparation processes (DB, subversion, etc.)
  * improved compare algorithm
