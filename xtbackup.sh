#!/bin/sh

php_bin=`which php`
xtbackup_dir=`readlink -f $0`
xtbackup_dir=`dirname $xtbackup_dir`

cd "$xtbackup_dir"
$php_bin -d open_basedir="$XTBACKUP_OPENDIR:/tmp:$xtbackup_dir" -f xtbackup.php -- $@
