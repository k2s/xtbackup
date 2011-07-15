<?php
/**
 * Exists mainly for easier developing of new outputs without the need to fully implement Output_Interface
 */
class Output_Empty implements Output_Interface
{
    protected $_options = array();

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
    public function finish()
    {

    }
    public function logDebug()
    {
        
    }

    public function logCritical()
    {

    }

    public function jobStart()
    {

    }

    public function jobEnd()
    {

    }

    public function jobPause()
    {

    }
    public function jobStep($step=1)
    {

    }
    public function mark()
    {
        
    }
    
    public function time()
    {
        
    }
}