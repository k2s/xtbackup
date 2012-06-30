<?php
interface Storage_Mysql_IBackup
{
    function setConnection($connection);
    function setDatabaseToBackup($name);
    function listAvailableObjectsToBackup($kind=false);
    function doBackup($storeCallback);
}