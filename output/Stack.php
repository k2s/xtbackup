<?php
/**
 * Main output class
 *
 * Internally it uses output drivers to process requests.
 *
 * @method logDebug()
 * @method logNotice()
 * @method logWarning()
 * @method logError()
 * @method logCritical()
 * @method finish($returnEx)
 * @method showHelp()
 * @method jobStart()
 * @method jobEnd()
 * @method jobStep()
 * @method jobSetProgressStep()
 */
class Output_Stack
{
    const CRITICAL = 2;  // Critical: critical conditions
    const ERROR    = 3;  // Error: error conditions
    const WARNING  = 4;  // Warning: warning conditions
    const NOTICE   = 5;  // Notice: normal but significant condition
    const DEBUG    = 7;  // Debug: debug messages

    /**
     * Array of output objects to be used to process output
     *
     * @var array
     */
    protected $_stack = array();

    protected $_welcomeOnce = false;

    public function outputAdd($output)
    {
        $this->_stack[] = $output;
        $this->logDebug("output class ".get_class($output)." added");
    }

    public function outputRemove($key=null)
    {
        if (is_null($key)) {
            $this->_stack = array();
        } else {
            die("not implemented in outputRemove");
        }
    }

    public function __call($name, $params)
    {
        $ret = null;
        foreach ($this->_stack as $output) {
            $ret = call_user_func_array(array($output, $name), $params);
        }

        return $ret;
    }

    /**
     * Log message as critical and stop program execution
     *
     * @return void
     */
    public function stop()
    {
        $this->logCritical(func_get_arg(0));
        die;
    }

    public function welcome()
    {
        if (!$this->_welcomeOnce) {
            $this->__call("welcome", array());
            $this->_welcomeOnce = true;
        }
    }



    // TODO handle job*() methods
}