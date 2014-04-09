---
layout: post
date:   2014-01-28 09:00:06
title:  "6. Backup MySQL DB from Amazon RDS to Amazon S3"
categories: tutorial mysql rds s3
---

Example of setup that allow you to to backup your RDS database to S3 storage.

* One of the great features of this solution is that our RDS class creates snapshot of current db and then starts new instance of DB from which backup is made so you
have 100% guarantee of consistent data without affecting live instance whatsoever.
* Data are stored in the CSV files individually per table, so you can easily get data form just selected tables

## Amazon RDS database - example ini file

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

;;; new need to set compare service, we use sqlite version(only one implemented at the time of writing this)
compare.sqlite.file="~/Backups/xtbackupCompare.db"

storage.s3.bucket=<Amazon S3 bucket name>

;;; generate "Access Keys" in Security Credentials in https://aws-portal.amazon.com/gp/aws/developer/account/
; This needs to be filled in if you want ot use s3 as storage, however prefered way is to use private INI file
; that can be stored in the more secure folder with limited access. So if you do not use separate INI file please
; uncomment below lines and fill in credentials.
;storage.s3.key.access=<access key>
;storage.s3.key.secret=<secret>

; set to true if you want to modify data in S3, if you just dry run it set it to simulate
storage.s3.update=true


;;; let us put everything together
engine.outputs[]=cli
engine.local=mysqlAmazonRds
engine.remote=s3
engine.compare=sqlite
```
