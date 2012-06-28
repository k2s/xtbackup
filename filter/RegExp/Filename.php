<?php

require_once "Abstract.php";
/**
 * Description of Storage_Filesystem_Filter_FilenameFilter
 *
 * @package Xtbackup
 */
class Filter_Filename extends Filter_RegExp_Abstract
{
    
    
    public function accept()
    {
        return (!$this->isFile() || !preg_match($this->regex, $this->getFilename()));
    }
   
}
