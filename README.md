xtBackup
========

Development of this project was sponsored by [xtmotion.com](http://www.xtmotion.com).

**!!! THIS PROJECT IS IN BETA STAGE !!!**

You should use it only for testing purposes and not in production.
The Authors will not be responsible for any damage users may suffer, including but not limited to, loss of data.

Requirements
------------

* PHP5
  * with SQLite support
* current implementation of the PHP-AWS library doesn't support files over 2GB on 32bit architecture

Installation
------------

* get code from <http://github.com/k2s/xtbackup>
  * git clone git://github.com/k2s/xtbackup.git
  * cd xtbackup
  * git submodule update --init
* copy examples/minimal.ini and examples/s3access.ini to own folder
* modify this files (see Configuration section)
* test environment for Amazon S3 backups (read the output): `php -f xtbackup.php ini[]=/path_to/minimal.ini storage.s3.compatibilityTest=true`
* dry run with : `php -f xtbackup.php ini[]=/path_to/s3access.ini ini[]=/path_to/minimal.ini`
* to really upload files: `php -f xtbackup.php ini[]=/path_to/s3access.ini ini[]=/path_to/minimal.ini storage.s3.update=true`

Configuration
-------------

### Quick

* run: `xtbackup.php --init > myconfig.ini`
* edit myconfig.ini
* run: `xtbackup.php ini[]=myconfig.ini`

### Configuration explained

`xtbackup` is build as library which may be included into different programs.
The controll programm `xtbackup.php` is provided to make backup task functionality available and it makes it possible to execute backup tasks.

`xtbackup.php` by itself interprets following command line options:

* --help : will output basic information how to use the programm
* --init : generate documented INI skeleton
* --quite-start : it is possible to suppress output to console in start phase of engine

All other command line parameters passed to `xtbackup.php` are forwarded to `xtbackup engine` as configuration options.

Most of configuration is done in INI file(s), but command line passed options have precedence over INI definitions.
Special case is the `ini[]` options which can't be used in INI files.
It is possible to define multiple `ini[]` options on command line, they precedence is given in order they are listed.
The purpose of multiple `ini[]` options and precedence of command line options over INI is:
* sharing of configuration
* security, credentials may be stored in private INI files

To start with backup follow:

* run: `xtbackup.php --init > myconfig.ini`
* edit myconfig.ini
* run: `xtbackup.php ini[]=myconfig.ini`

### Configuration reference

Read output of `xtbackup.php --init`, that is all we have and yes, you are welcome to contribute with more and better documentation.

### Program output

xtbackup.php
------------

### Return codes

### Creating PHAR package

Support
-------

Submit issues or support requests to <http://github.com/k2s/xtbackup/issues>.

Resources
---------

* working with Amazon S3:
  * <http://www.dragondisk.com>
* writing with Markup
  * <http://daringfireball.net/projects/markdown/syntax>
  * <http://daringfireball.net/projects/markdown/dingus>
  * <http://github.github.com/github-flavored-markdown>

Contributors
------------

* work donated by [xtmotion.com](http://www.xtmotion.com)
* Martin Minka
* Alex Melnyk
* Artem Komarov

Credits
-------

xtBackup uses following libraries:

* [SQLite](http://www.sqlite.org/)
* [PHP-AWS library](http://github.com/tylerhall/php-aws/)

License
-------

xtBackup is free and unencumbered public domain software. For more information, see http://unlicense.org/ or the accompanying UNLICENSE file.