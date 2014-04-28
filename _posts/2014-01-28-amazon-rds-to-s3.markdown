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

{% highlight ini linenos %}
;;; Amazon RDS configuration
storage.mysqlAmazonRds.storage = mysql
storage.mysqlAmazonRds.dbinstance = <dbinstancename>
storage.mysqlAmazonRds.region = REGION_EU_W1
storage.mysqlAmazonRds.dbinstanceclass = db.m1.small

;;; we want to backup all databases on server
storage.mysql.dbname = *

;;; connect to mysql server on RDS to build backup
;storage.mysql.port = 3306
storage.mysql.user = username
storage.mysql.password = userpassword

;;; databases will be backuped under this folder
storage.mysql.basedir = "~/Backups/amazonRdsDb"

compare.sqlite.file="~/Backups/xtbackupCompare.db"

storage.s3.bucket=<Amazon S3 bucket name>

;storage.s3.key.access=<access key>
;storage.s3.key.secret=<secret>

storage.s3.update=true

;;; let us put everything together
engine.outputs[]=cli
engine.local=mysqlAmazonRds
engine.remote=s3
engine.compare=sqlite
{% endhighlight %}

line 3: DB Instance Identifier as specified in the AWS console

line 4: you can specify where the backup instance should be created

line 5: you can specify class of instance, allows some saving if you use smaller instance that used for the live instance

line 18: new need to set compare service, we use sqlite version(only one implemented at the time of writing this) set it up in a persistent space (not tmp) 

line 22-23:
Generate "Access Keys" in Security Credentials in [your AWS console](https://aws-portal.amazon.com/gp/aws/developer/account/)  
This needs to be filled in if you want ot use s3 as storage, however preferred way is to use private INI file
that can be stored in the more secure folder with limited access. That way you can avoid accidentally committing
your AWS credentials to potentially public repositories and losing $$$ in the process [http://pulse.me/s/13oajD](http://pulse.me/s/13oajD).  
So if you do not use separate INI file please uncomment this lines and fill in credentials.

line 25: should upload/remove of data be executed? (yes/no/simulate)   
    yes -      will transfer data to S3  
    no -       will not start update process  
    simulate - will output progress, but will not really transfer data

