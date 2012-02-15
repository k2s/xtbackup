<?php
/**
 * Mandatory methods for storage driver
 */
interface Storage_Interface {

    function init($myrole, $drivers);
    /*function refreshLocal($myrole, $drivers);
    function refreshRemote($myrole, $drivers);
    function updateRemote($myrole, $drivers);*/
    function getBaseDir();
    function getMd5($path);
    static function getConfigOptions($part=null);
    function convertEncodingPath($path);
}
