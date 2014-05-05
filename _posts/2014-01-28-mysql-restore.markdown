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

Quite nice functionality mainly useful for developers is a option to apply filters on data you want importing. That way you don't need to import huge log tables that 
you don't really need on your local machine.

The way you would go about it is as follows. First create filter file, php example below(can be any other type that will respond correctly).

{% highlight php linenos %}
<?php
$dbName = $argv[1];
$action = $argv[2];
$objectName = $argv[3];

switch ($action) {
   case "test":
       // control value
       exit(123);
   case "data":
       // restrict what data we want to import
       if ($objectName[0]=="_") {
           exit(0);
       }

       if (substr($objectName, 0, 3)=="log_") {
           exit(0);
       }

       break;
}

exit(1);

{% endhighlight %}

lines 2-4 setup variables to receive input

lines 7-9 in order for filter file to be accepted it needs correctly respond to test challenge (response must be '123')

lines 12,16 add conditions that checks if current object should be skipped 
 
lines 13,17 exist with code 0 to skip operation on given object

line 23 exist with code 1 to continue operations on current object

To run it you can use this command:
``` ini
php -f ~/backup/restore.php -- --drop-db -h localhost -u root -p -F "php -f ~/backup/filter.php " ~/backup/
```

Of course you can run script on the backup to remove actual data from backup before you download it. You could go about in this way:

``` ini
php -f ~/backup/restore.php -- --clone-to "~/backup/filtered" -F "php -f ~/backup/filter.php " ~/backup/
```

