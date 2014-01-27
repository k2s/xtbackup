<?php
interface Output_Interface
{
    function log($priority, $message);
    function logDebug();
    function logNotice();
    function logWarning();
    function logError();
    function logCritical();

    function welcome();
    function finish($returnEx);
    function showHelp();

    function jobStart($msg, $params=array());
    function jobEnd($job, $msg, $params=array());
    function jobStep($job);
    function jobSetProgressStep($job, $step);

    function mark();
    function time();

    static function getConfigOptions($part=null);
}
