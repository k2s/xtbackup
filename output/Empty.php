<?php
/**
 * Exists mainly for easier developing of new outputs without the need to fully implement Output_Interface
 */
class Output_Empty implements Output_Interface
{
    protected $_options = array();

    /**
     * Stack of active jobs
     *
     * @var array
     */
    protected $_jobs = array();

    public function  __construct($options)
    {
        $this->_options = $options;
    }

    public function init()
    {
        
    }
    public function welcome()
    {

    }
    public function finish($returnEx)
    {

    }
    public function logDebug()
    {
        
    }

    public function logCritical()
    {

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

    }

    public function jobSetProgressStep($job, $step)
    {
        $this->_jobs[$job]['step'] = $step;
    }

    public function jobPause()
    {

    }
    public function jobStep($job)
    {

    }
    public function mark()
    {
        
    }
    
    public function time()
    {
        
    }

    static public function getConfigOptions($part = null)
    {
        // TODO: Implement getConfigOptions() method.
        return array();
    }
}