<?php
interface Storage_Mysql_IBackup
{
    function setOutput($out);
    function setConnection($connection);
    function setDatabaseToBackup($name);
    function setDataCompression($compressDataFiles);
    function listAvailableObjectsToBackup($kind=false);
    function doBackup($storeCallback);
}