<?php
require_once 'output/Blackhole.php';

class Output_Cli extends Output_Blackhole
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
        $ver = "* v".Core_Engine::getVersion();
        $ver .= str_repeat(" ", 34-strlen($ver))."*";
        fputs(STDOUT, "***********************************".PHP_EOL);
        fputs(STDOUT, "* xtBackup                        *".PHP_EOL);
        fputs(STDOUT, $ver.PHP_EOL);
        fputs(STDOUT, "* sponsored by xtmotion.com, 2011 *".PHP_EOL);
        fputs(STDOUT, "***********************************".PHP_EOL);
        fputs(STDOUT, PHP_EOL);
    }
    /**
     * Last output
     *
     * @param $returnEx Core_StopException|bool
     *
     * @return void
     */
    public function finish($returnEx)
    {
        if (false===$returnEx) {
            fputs(STDOUT, "done.");
        } else {
            fputs(STDERR, "error.");
        }
    }

    public function showHelp($hint=false)
    {
        if ($hint) {
            fputs(STDOUT, PHP_EOL."$hint".PHP_EOL.PHP_EOL);
        }
    }

    /**
     * Log message with priority
     *
     * @param int    $priority use Zend_Log priorities
     * @param string $message  Message to log, multiple values supported
     *
     * @return void
     * @throws Zend_Log_Exception
     */
    public function log($priority, $message)
    {
        $args = func_get_args();
        $priority = array_shift($args);

        // check verbosity setting
        if ($this->_verbosity<=$priority) {
            return;
        }

        // build output string
    	$msg = array_shift($args);
        if (count($args)>0) {
            $msg = vsprintf($msg, $args);
        }

        // decorate output
        switch ($priority) {
            case Output_Stack::WARNING:
                $msg = "WARNING: ".$msg;
                break;
            case Output_Stack::ERROR:
                $msg = "ERROR: ".$msg;
                break;
            case Output_Stack::CRITICAL:
                $msg = "CRITICAL: ".$msg;
                break;
        }

        // decide about output stream and process
        if ($priority>=Output_Stack::WARNING) {
            fputs(STDOUT, $msg.PHP_EOL);
        } else {
            fputs(STDERR, $msg.PHP_EOL);
        }
    }

    public function logDebug()
    {
        $args = func_get_args();
        array_unshift($args, Output_Stack::DEBUG);
        call_user_func_array(array($this, 'log'), $args);
    }

    public function logError()
    {
        $args = func_get_args();
        array_unshift($args, Output_Stack::ERROR);
        call_user_func_array(array($this, 'log'), $args);
    }

    public function logCritical()
    {
        $args = func_get_args();
        array_unshift($args, Output_Stack::CRITICAL);
        call_user_func_array(array($this, 'log'), $args);
    }

    public function logNotice()
    {
        $args = func_get_args();
        array_unshift($args, Output_Stack::NOTICE);
        call_user_func_array(array($this, 'log'), $args);
    }

    public function logWarning()
    {
        $args = func_get_args();
        array_unshift($args, Output_Stack::WARNING);
        call_user_func_array(array($this, 'log'), $args);
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
        // TODO has to exists in Output_Stack and maintain jobId
    	$params = func_get_args();

        // init new job
        $job = ++$this->_lastJobId;
        $this->_jobs[$job] = array('count'=>0, 'step'=>1, 'level'=>count($this->_jobs));

    	$msg = array_shift($params);
        if (count($params)>0) {
            $msg = vsprintf($msg, $params);
        }
        fputs(STDOUT, "(job $job) start: ".$msg.PHP_EOL);

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
                fputs(STDOUT, ".");
            }
            fputs(STDOUT, PHP_EOL);
        }

        // show messsage
        fputs(STDOUT, "(job $job) end: ".$msg.PHP_EOL);

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
            fputs(STDOUT, ".");
        }
    }

    public function jobSetProgressStep($job, $step)
    {
        $this->_jobs[$job]['step'] = $step;
    }
    
    public function mark()
    {
        // TODO remove or implement elsewhere (Output_Stack ?)
        $mtime = microtime(); 
        $mtime = explode(" ",$mtime); 
        $mtime = $mtime[1] + $mtime[0]; 
        $this->_startTime = $mtime; 
    }
    
    public function time()
    {
        // TODO remove or implement elsewhere (Output_Stack ?)
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
                'verbosity'=>Output_Stack::DEBUG,
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
            return array_key_exists($part, $opt) ? $opt[$part] : array();
        }
    }
}