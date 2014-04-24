---
layout: post
title:  "2. Mirror files"
date:   2014-01-28 09:00:02
categories: tutorial files
---

[Basic]({% post_url 2014-01-28-basic %})

Let us start with simple example where we mirror `local` directory to `remote` directory.

This configuration will backup/mirror our pictures from user home folder `~/Pictures` (use something like `c:\Users\me\Pictures on Windows`)
to other local folder (for example mounted NAS in `/mnt/nas`).

{% highlight ini linenos %}
storage.filesystem.basedir=~/Pictures
storage.target.basedir=/mnt/nas
storage.target.class = Storage_Filesystem
compare.sqlite.file=~/xtbackup.db

; engine.outputs[]=cli
engine.local=filesystem
engine.remote=target
engine.compare=sqlite
{% endhighlight %}

line 1: We configure source for data. Data are handled by storage driver, so the first key is `storage`. Second key is name of
the storage configuration. It could be any name, but because we have chosen same name as name of driver (`filesystem`) we don't need to specify
drivers class, it will be deducted by xtbackup.

line 2: We configure target for data. Again, it is about data, it will be handled by storage driver, so it is defined under `storage` key.
We name this storage configuration as `target`.

line 3: We have to manually specify driver class, because the driver class to be used with `target` configuration will not be automaticaly deducted by xtbackup.

line 4: Except storage drivers we have to configure compare driver. Minimal configuration requires where sqlite database file should be stored.
This location shouldn't be on temporary device,

Next lines configure engine driver and bind everything together.

line 7: the key `local` maps storage configuration key which will be used as data source

line 8: the key `remote` maps storage configuration key which will be used as data target

line 9: the key `compare` maps compare configuration key
