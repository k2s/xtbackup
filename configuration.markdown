---
layout: default
title: xtbackup Site
---
Configuration
-------------

### Quick

* run: `php -f xtbackup.php -- --init > myconfig.ini`
* edit myconfig.ini
* run: `php -f xtbackup.php -- ini[]=myconfig.ini`

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

{% highlight ini %}
{% include fullini.markdown %}
{% endhighlight %}