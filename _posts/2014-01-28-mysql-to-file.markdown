---
layout: post
date:   2014-01-28 09:00:04
title:  "4. Backup MySQL DB to directory"
categories: "tutorial mysql file"
---
  
   
## Basic ini file


{% highlight ini linenos %}
;;; we want to backup selected databases on server
storage.mysql.dbname = testdb

;;; connect to mysql server
storage.mysql.host = localhost
storage.mysql.port = 3306
storage.mysql.user = root
storage.mysql.password =

;;; configure rotation schema
storage.mysql.rotate.days = 7
storage.mysql.rotate.weeks = 4
storage.mysql.rotate.months = 3

;;; configure rotation schema specifics for database
storage.mysql.dbname.testdb.rotate.days = 2
storage.mysql.dbname.testdb.rotate.weeks = 0
storage.mysql.dbname.testdb.rotate.months = 0

;;; databases will be backed up under this folder change as required
storage.mysql.basedir = "~/Backups"

storage.dummy =

compare.sqlite.file="~/Backups/xtbackupCompare.db"

;;; let us put everything together
engine.outputs[]=cli
engine.local=mysql
engine.remote=dummy
engine.compare=sqlite
{% endhighlight %}

line 2: we specify name of DB we want to backup see examples below for multiple/all DBs settings
   
lines 5-8: set mysql connection details   

lines 11-13: set value to storage.mysql.rotate.days  to define for how many days should backups be kept same logic applies for weeks and months

line 23: we don't want to move the files to other backup storage, so we use dummy driver

line 25: new need to set compare service, we use sqlite version(only one implemented at the time of writing this) 

## All Databases backup - example ini file

Same as basic example, but we specify that we want to backup all DBs

``` ini
;;; we want to backup all databases on server
storage.mysql.dbname = *

```

## Multiple Databases - example ini file
{% highlight ini linenos %}
;;; we want to backup only DBs named "mysql" and "test"
storage.mysql.dbname[] = mysql
storage.mysql.dbname.test.compressdata = true
storage.mysql.dbname.test.addtobasedir = test
{% endhighlight %}

lines 3-4: if you want to specify parameters for individual DBs you can use this type of syntax


