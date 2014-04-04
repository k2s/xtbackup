---
layout: default
title: xtBackup Configuration
---
FAQ
-------------

### General ###

#### Why PHP ####

### MySQL ###

#### What format do have files in data folder ####

Files in data/ folder with extension .z are compressed with gzip.

Files without extensions are regular CSV files with columns in same order as defined in /table folder.

#### How to manually uncompress data/*.z files ####

{% highlight bash %}
# uncompress and keep original *.z file
gunzip -c table.z > table
# uncompress and remove original *.z file
gunzip cms_page.z
{% endhighlight %}

The resulting file is CSV file loadable with MySQL LOAD DATA method.

#### How to remove extracted/compressed files from data folder ####

{% highlight bash %}
# dry run to see what will be done
php -f restore.php -- --remove-files csv /xtdbbackup/path
# remove physicaly the files
php -f restore.php -- --remove-files ^csv /xtdbbackup/path
{% endhighlight %}

