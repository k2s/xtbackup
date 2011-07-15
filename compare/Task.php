<?php
class Task
{
    /**
     * @var string
     */
    public $action;
    /**
     * @var array
     */
    protected $_data;

    public function __construct($result)
    {
        if (!empty($result)) {
            $this->action = $result['action'];
            $this->_data = $result;
        }
    }
    public function __get($name) 
    {
        // TODO why this complicated way ?
        // why is this class not simply container of known values used in storage and compare, this is too open to be good API
        if (array_key_exists($name, $this->_data)) {
            return $this->_data[$name];
        } else {
            return false;
        }
    }
}