---
layout: default
title: xtBackup Install
---
Requirements
------------

* PHP 5.3 and above
  * with SQLite support

Installation
------------

* get code from <http://github.com/k2s/xtbackup>
{% highlight bash %}
git clone git://github.com/k2s/xtbackup.git
cd xtbackup
git submodule update --init
{% endhighlight %}
* copy `examples/minimal.ini` and `examples/s3access.ini` to own folder
* modify this files (see [Configuration](configuration.html) section)
* optionally test environment for Amazon S3 backups (read the output):
{% highlight bash %}php -f xtbackup.php -- ini[]=/path_to/minimal.ini storage.s3.compatibility-test=true{% endhighlight %}
* dry run with:
{% highlight bash %}php -f xtbackup.php -- ini[]=/path_to/s3access.ini ini[]=/path_to/minimal.ini{% endhighlight %}
* to really upload files
{% highlight bash %}php -f xtbackup.php -- ini[]=/path_to/s3access.ini ini[]=/path_to/minimal.ini storage.s3.update=true{% endhighlight %}
