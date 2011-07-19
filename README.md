xtBackup
========

Development of this project was sponsored by [xtmotion.com](http://www.xtmotion.com).

**!!! THIS PROJECT IS IN ALPHA STATE !!!**
You should use it only for testing purposes and not in production.
The Authors will not be responsible for any damage users may suffer, including but not limited to, loss of data.

Requirements
------------

* PHP5
  * with SQLite support
* current implementation of the PHP-AWS library doesn't support files over 4GB on 32bit architecture

Installation
------------

* get code from <http://github.com/k2s/xtbackup>
  * git clone git://github.com/k2s/xtbackup.git
  * cd xtbackup
  * git submodule update --init
* copy examples/minimal.ini and examples/s3access.ini to own folder
* modify this files (see Configuration section)
* dry run with : `php -f xtbackup.php ini[]=/path_to/s3access.ini ini[]=/path_to/minimal.ini`
* to really upload files: `php -f xtbackup.php ini[]=/path_to/s3access.ini ini[]=/path_to/minimal.ini storage.s3.update=true`

Configuration
-------------

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