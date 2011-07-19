<?php
require_once 'output/Empty.php';

class Output_Cli extends Output_Empty
{
    /**
     * ID value of last started job
     *
     * @var int
     */
    protected $_lastJobId = 0;
    /**
     * Level of verbosity
     *
     * @var int
     */
    protected $_verbosity;

    protected $_startTime = 0;

    /**
     * Constructor
     *
     * @param array $options configuration options
     */
    public function  __construct($options)
    {
        // merge options with default options
        $options = Core_Engine::array_merge_recursive_distinct(self::getConfigOptions(CfgPart::DEFAULTS), $options);

        // remember configuration options
        $this->_options = $options;
        $this->_verbosity = $options['verbosity']; // make copy for faster access

        // make sure that all output is directly sent to console
        ob_implicit_flush();
    }
    /**
     * First output
     *
     * @return void
     */
    public function welcome()
    {
        echo "***********************************\n";
        echo "* xtBackup                        *\n";
        echo "* sponsored by xtmotion.com, 2011 *\n";
        echo "********************************* *\n";
        echo "\n";
    }
    /**
     * Last output
     *
     * @return void
     */
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

    /**
     * Start a new job and return pointer to it
     *
     * @param string $msg    message to show
     * @param array  $params multiple values substituted into $msg (@see vsprintf)
     *
     * @return int
     */
    public function jobStart($msg, $params=array())
    {
    	$params = func_get_args();

        // init new job
        $job = ++$this->_lastJobId;
        $this->_jobs[$job] = array('count'=>0, 'step'=>1, 'level'=>count($this->_jobs));

    	$msg = array_shift($params);
        if (count($params)>0) {
            $msg = vsprintf($msg, $params);
        }
        echo "(job $job) start: ".$msg."\n";

        return $job;
    }

    /**
     * Signal job end
     *
     * @param int    $job    job id
     * @param string $msg    message to show
     * @param array  $params multiple values substituted into $msg (@see vsprintf)
     *
     * @return void
     */
    public function jobEnd($job, $msg, $params=array())
    {
    	$params = func_get_args();
    	$job = array_shift($params);
        $msg = array_shift($params);

        if (count($params)>0) {
            // substitute variables in message
            $msg = vsprintf($msg, $params);
        }

        // new line needed if dots were printed out
        $count = &$this->_jobs[$job]['count'];
        $step = &$this->_jobs[$job]['step'];
        if ($count) {
            if ($count % $step != 0) {
                // output dot for not completed batch
                echo ".";
            }
            echo "\n";
        }

        // show messsage
        echo "(job $job) end: ".$msg."\n";

        // remove this job from stack
        unset($this->_jobs[$job]);
    }

    public function jobStep($job=null)
    {
        $count = &$this->_jobs[$job]['count'];
        $step = &$this->_jobs[$job]['step'];
        $count++;
        if ($count % $step == 0) {
            // mostly we don't want to show progress on each step
            echo ".";
        }
    }

    public function jobSetProgressStep($job, $step)
    {
        $this->_jobs[$job]['step'] = $step;
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