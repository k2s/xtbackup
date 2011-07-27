<?php
require_once 'Interface.php';
require_once 'RegExp/Dirname.php';
require_once 'RegExp/Filename.php';
/**
 * Because of windows local storage is completely implemented on COM
 * this filter currently apllied on non-windows platforms. And filter interface
 * can be implemeneted on non-windows platforms.
 * On windows used "inline" regexp filter. 
 *  
 * 
 * 
 * @author oleksandr.melnyk
 */
class Filter_RegExp implements Filter_Interface
{
    /**
     *
     * @var array
     */
    protected $_config;
    /**
     *
     * @var RecursiveDirectoryIterator instance
     */
    protected $_iterator;
    
    public function __construct($config = array()) 
    {
        $this->_config = $config;
        if (isset($this->_config['basedir'])) {
            $this->_iterator = new RecursiveDirectoryIterator($this->_config['basedir']);
        }
    }
    /**
     * Set iterator
     *
     * @param string|RecursiveDirectoryIterator $directory instance $directory
     * 
     * @return void
     */
    public function setIterator($directory)
    {
        if (is_string($directory)) {
            $this->_iterator = new RecursiveDirectoryIterator($directory);
        } else {
            $this->_iterator = $directory;
        }
    }
    
    public function applyFilters() 
    {
        if (isset($this->_config['config']['dirnameFilter'])) {
            $this->_iterator =  new Filter_Dirname($this->_iterator, $this->_config['config']['dirnameFilter']);
        }
        
        if (isset($this->_config['config']['filenameFilter'])){
            $this->_iterator = new Filter_Filename($this->_iterator, $this->_config['config']['filenameFilter']);
        }
        
        return $this->_iterator;
    }

    static public function getConfigOptions($part = null)
    {
        // TODO: Implement getConfigOptions() method.
    }
}
