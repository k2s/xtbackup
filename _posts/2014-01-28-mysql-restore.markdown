---
layout: post
date:   2014-01-28 09:00:08
title:  "7. Restore MySql backup"
categories: "tutorial mysql restore"
---

Examples about how to restore data from the backup. When backup run it creates restore.php file within backup folder.
You need to run it in the command line like this:

```ini
php -f restore.php -- [<parameters>] [<backup folder>]

```
Parameters:

-u, --user	mysql user name under which we will perform restore
-p, --password	mysql user password
-h, --host	mysql server host name
-P, --port	mysql server port number
-S, --socket	mysql server port number
-D, --database	target database name
--drop-db		will drop DB if exists
--no-data		skip data import
--force-local-server		force data load to use method optimal for local server
-a, --actions	restore actions to execute (default is u,f,t,i,d,r,v,p,tr,g):
    u - users
    f - functions
    t - structure of tables
    i - indexes
    d - table data
    r - references
    v - views
    p - procedures
    tr- triggers
    g - permission grants
--create-index		(before|after) data load
-F, --filter-ext	external command which returns 1 if object action should be processes
-C, --clone-to	provide folder where you want to copy backup data, filter will be applied, if value ends with zip data will be compressed
-do, --decompress-only	decompress data files only
-df, --decompress-folder	if data have to be uncompressed it will happen into data folder, you may change this with this option
-da, --decompress-action	if data had to be decompressed on import this will happen after import completes:
	delete - delete decompressed
	keep - keep decompressed and compressed
	replace - keep decompressed and delete compressed
-f, --force	will not prompt user to approve restore
-q, --quite	will not print messages
--log-process		print messages describing restore process (0=off, 1=on)
--log-sql-warn		print MySQL server warning messagesv (0=off, 1=on)
--log-sql-exec		print executed SQL statements (0=off, 1=all, 2=if SQL warning found)
-?, --help	display instruction how to use cli.php


