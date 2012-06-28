<?php
require_once "Abstract.php";
/**
 * Description of Dirname
 *
 * @author mamay
 */
class Filter_Dirname extends Filter_RegExp_Abstract
{
    
    
    public function accept() 
    {
        return (!$this->isDir() || !preg_match($this->regex, $this->getFilename()));
    }
}

