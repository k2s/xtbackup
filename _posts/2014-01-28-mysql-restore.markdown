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

<code>-u, --user</code> mysql user name under which we will perform restore   
<code>-p, --password</code> mysql user password   
<code>-h, --host</code> mysql server host name  
<code>-P, --port</code> mysql server port number  
<code>-S, --socket</code> mysql server port number  
<code>-D, --database</code> target database name  
<code>--drop-db</code>	-	will drop DB if exists  
<code>--no-data</code>	-	skip data import  
<code>--force-local-server</code> - force data load to use method optimal for local server  
<code>-a, --actions</code> restore actions to execute (default is u,f,t,i,d,r,v,p,tr,g):  
>    u - users  
>    f - functions  
>    t - structure of tables  
>    i - indexes   
>    d - table data  
>    r - references  
>    v - views  
>    p - procedures  
>    tr- triggers  
>    g - permission grants  

<code>--create-index</code>	- (before|after) data load  
<code>-F, --filter-ext</code> - external command which returns 1 if object action should be processes  
<code>-C, --clone-to</code> - provide folder where you want to copy backup data, filter will be applied, if value ends with zip data will be compressed  
<code>-do, --decompress-only</code> - decompress data files only  
<code>-df, --decompress-folder</code> - if data have to be uncompressed it will happen into data folder, you may change this with this option  
<code>-da, --decompress-action</code> - if data had to be decompressed on import this will happen after import completes:  
>	delete - delete decompressed  
>	keep - keep decompressed and compressed  
>	replace - keep decompressed and delete compressed

<code>-f, --force</code>	- will not prompt user to approve restore  
<code>-q, --quite</code>	- will not print messages  
<code>--log-process</code>	-	print messages describing restore process (0=off, 1=on)  
<code>--log-sql-warn</code>	-	print MySQL server warning messagesv (0=off, 1=on)  
<code>--log-sql-exec</code>	-	print executed SQL statements (0=off, 1=all, 2=if SQL warning found)  
<code>-?, --help</code> - display instruction how to use cli.php  


