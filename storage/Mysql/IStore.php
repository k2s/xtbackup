<?php
interface Storage_Mysql_IStore
{
    function storeDbObject($kind, $name, $def);
    function storeFilenameFor($kind, $name);
}