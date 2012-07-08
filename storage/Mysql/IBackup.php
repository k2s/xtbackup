<?php
interface Storage_Mysql_IBackup
{
    function setConnection($connection);
    function setDatabaseToBackup($name);
    function setDataCompression($compressDataFiles);
    function listAvailableObjectsToBackup($kind=false);
    function doBackup($storeCallback);
}