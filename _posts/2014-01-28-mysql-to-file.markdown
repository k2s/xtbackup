---
layout: post
date:   2014-01-28 09:00:04
title:  "4. Backup MySQL DB to directory"
categories: "tutorial mysql file"
---

# Basic


## All Databases backup

``` ini
;;; we want to backup all databases on server
storage.mysql.dbname = *

;;; connect to mysql server on localhost as user 'root' without password to build backup
storage.mysql.host = localhost
storage.mysql.port = 3306
storage.mysql.user = root
storage.mysql.password =

;;; databases will be backuped under this folder
storage.mysql.basedir = "~/Backups/allDb"

;;; we don't want to move the files to other backup storage, so we use dummy driver
storage.dummy =

;;; there is not dummy class for compare services, so we use sqlite verions
compare.sqlite.file="~/Backups/xtbackupCompare.db"

;;; let us put everything together
engine.outputs[]=cli
engine.local=mysql
engine.remote=dummy
engine.compare=sqlite
```

## Selected Database

Same as above but we specify DB name here

``` ini
;;; we want to backup all databases on server
storage.mysql.dbname = yourdbname
```
## Multiple Databases
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

