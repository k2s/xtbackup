---
layout: post
date:   2014-01-28 09:00:04
title:  "4. Backup MySQL DB to directory"
categories: "tutorial mysql file"
---

## Usage

To run backup you need to execute following command:

``` bash
php -f xtbackup.php -- ini[]=/path_to/your_ini_file.ini
```
of course this should ideally be added to the cron with MAILTO directive setup so you get notification.

You can specify what level of verbosity should be used with following option
output.cli.verbosity=WARNING

Possible values: CRITICAL, ERROR, WARNING, NOTICE, DEBUG default being NOTICE

## Basic ini file


``` ini
;;; we want to backup selected databases on server
storage.mysql.dbname = testdb

;;; connect to mysql server on localhost as user 'root' without password to build backup
storage.mysql.host = localhost
storage.mysql.port = 3306
storage.mysql.user = root
storage.mysql.password =

;;; configure rotation schema
; set value to
; storage.mysql.rotate.days =
; to define for how many days should backups be kept
; same logic applies for weeks and months

storage.mysql.rotate.days = 7
storage.mysql.rotate.weeks = 4
storage.mysql.rotate.months = 3

;;; configure rotation schema specifics for database
storage.mysql.dbname.testdb.rotate.days = 2
storage.mysql.dbname.testdb.rotate.weeks = 0
storage.mysql.dbname.testdb.rotate.months = 0


;;; databases will be backed up under this folder change as required
storage.mysql.basedir = "~/Backups"

;;; we don't want to move the files to other backup storage, so we use dummy driver
storage.dummy =

;;; new need to set compare service, we use sqlite version(only one implemented at the time of writing this)
compare.sqlite.file="~/Backups/xtbackupCompare.db"

;;; let us put everything together
engine.outputs[]=cli
engine.local=mysql
engine.remote=dummy
engine.compare=sqlite
```



## All Databases backup - example ini file

Same as basic example, but we specify that we want to backup all DBs

``` ini
;;; we want to backup all databases on server
storage.mysql.dbname = *

```

## Multiple Databases - example ini file
``` ini
;;; we want to backup only DBs named "mysql" and "test"
storage.mysql.dbname[] = mysql
storage.mysql.dbname.test.compressdata = true
storage.mysql.dbname.test.addtobasedir = test
```
or if you want to specify params for individual DBs you can use this type of syntax

``` ini
storage.mysql.dbname.test.compressdata = true
storage.mysql.dbname.test.addtobasedir = test
```

## Amazon RDS database - example ini file

One of the great features of this solution is that our RDS class creates snapshot of current db and then starts new instance of DB from which backup is made so you
have 100% guarantee of consistent data without affecting live instance whatsoever.

``` ini
;;; Amazon RDS configuration
storage.mysqlAmazonRds.storage = mysql
storage.mysqlAmazonRds.dbinstance = <dbinstancename>
; you can specify where the backup instance should be created
storage.mysqlAmazonRds.region = REGION_EU_W1
; you can specify class of instance (allow some saving if you use smaller instance that used for the live instance
storage.mysqlAmazonRds.dbinstanceclass = db.m1.small

;;; we want to backup all databases on server
storage.mysql.dbname = *

;;; connect to mysql server on RDS to build backup
;storage.mysql.port = 3306
storage.mysql.user = username
storage.mysql.password = userpassword

;;; databases will be backuped under this folder
storage.mysql.basedir = "~/Backups/amazonRdsDb"

;;; we don't want to move the files to other backup storage, so we use dummy driver that will cause that files are stored locally
storage.dummy =

;;; new need to set compare service, we use sqlite version(only one implemented at the time of writing this)
compare.sqlite.file="~/Backups/xtbackupCompare.db"

;;; let us put everything together
engine.outputs[]=cli
engine.local=mysqlAmazonRds
engine.remote=dummy
engine.compare=sqlite
```


