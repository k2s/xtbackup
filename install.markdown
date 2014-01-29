---
layout: default
title: xtbackup Site
---
Requirements
------------

* PHP 5.3 and above
  * with SQLite support

Installation
------------

* get code from <http://github.com/k2s/xtbackup>
  * git clone git://github.com/k2s/xtbackup.git
  * cd xtbackup
  * git submodule update --init
* copy examples/minimal.ini and examples/s3access.ini to own folder
* modify this files (see Configuration section)
* test environment for Amazon S3 backups (read the output): `php -f xtbackup.php ini[]=/path_to/minimal.ini storage.s3.compatibility-test=true`
* dry run with : `php -f xtbackup.php ini[]=/path_to/s3access.ini ini[]=/path_to/minimal.ini`
* to really upload files: `php -f xtbackup.php ini[]=/path_to/s3access.ini ini[]=/path_to/minimal.ini storage.s3.update=true`
