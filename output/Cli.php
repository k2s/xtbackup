<?php
require_once 'output/Empty.php';

class Output_Cli extends Output_Empty
{
    protected $_jobId = 0;
    
    protected $_startTime = 0;

    protected $_verbosity;

    protected $_hadStep = false;
    
    public function  __construct($options)
    {
        // merge options with default options
        $options = Core_Engine::array_merge_recursive_distinct(self::getConfigOptions(CfgPart::DEFAULTS), $options);

        // remember configuration options
        $this->_options = $options;
        $this->_verbosity = $options['verbosity']; // for faster access

        // make sure that all output is directly sent to console
        ob_implicit_flush();
    }
    public function welcome()
    {
        echo "***********************************\n";
        echo "* xtBackup                        *\n";
        echo "* sponsored by xtmotion.com, 2011 *\n";
        echo "********************************* *\n";
        echo "\n";
    }
    public function finish()
    {
        echo "done.\n";
    }

    public function logDebug()
    {
        if ($this->_verbosity<=Output_Stack::DEBUG) {
            return;
        }

    	$params = func_get_args();
    	$msg = array_shift($params);
        if (count($params)>0) {
            $msg = vsprintf($msg, $params);
        }
        echo "# $msg\n";
    }

    public function logError()
    {
    	$params = func_get_args();
    	$msg = array_shift($params);
        if (count($params)>0) {
            $msg = vsprintf($msg, $params);
        }
        echo "ERROR: $msg\n";
    }

    public function logCritical()
    {
    	$params = func_get_args();
    	$msg = array_shift($params);
        if (count($params)>0) {
            $msg = vsprintf($msg, $params);
        }
        echo "CRITICAL: $msg\n";
    }

    public function logNotice()
    {
    	$params = func_get_args();
    	$msg = array_shift($params);
        if (count($params)>0) {
            $msg = vsprintf($msg, $params);
        }
        echo "$msg\n";
    }

    public function logWarning()
    {
    	$params = func_get_args();
    	$msg = array_shift($params);
        if (count($params)>0) {
            $msg = vsprintf($msg, $params);
        }
        echo "WARNING: $msg\n";
    }

    public function jobStart()
    {
        $id = ++$this->_jobId;
    	$params = func_get_args();
    	$msg = array_shift($params);
        if (count($params)>0) {
            $msg = vsprintf($msg, $params);
        }
        echo "(job $id) start: ".$msg."\n";

        return $id;
    }

    public function jobEnd()
    {
    	$params = func_get_args();
    	$id = array_shift($params);
        $msg = array_shift($params);

        if (count($params)>0) {
            $msg = vsprintf($msg, $params);
        }
        if ($this->_hadStep) {
            $this->_hadStep = false;
            echo "\n";
        }
        echo "(job $id) end: ".$msg."\n";
    }

    public function jobStep($job=null, $step=1)
    {
        $this->_hadStep = true;
        echo ".";
    }
    
    public function mark()
    {
        $mtime = microtime(); 
        $mtime = explode(" ",$mtime); 
        $mtime = $mtime[1] + $mtime[0]; 
        $this->_startTime = $mtime; 
    }
    
    public function time()
    {
        $mtime = microtime(); 
        $mtime = explode(" ",$mtime); 
        $mtime = $mtime[1] + $mtime[0]; 
        $totaltime = ($mtime - $this->_startTime); 
        return number_format($totaltime, 5); 
    }

    static public function getConfigOptions($part=null)
    {
        $opt = array(
            CfgPart::DEFAULTS=>array(
                'verbosity'=>Output_Stack::NOTICE,
                'progress'=>true,
            ),
            CfgPart::DESCRIPTIONS=>array(
                'verbosity'=>'.....',
                'progress'=>"should progress be shown ?",
            ),
            CfgPart::REQUIRED=>array()
        );

        if (is_null($part)) {
            return $opt;
        } else {
            return $opt[$part];
        }
    }
}