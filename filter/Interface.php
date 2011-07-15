<?php
/**
 * Description of Interface
 *
 * @package xtbackup
 */
interface Filter_Interface
{
    /**
     * @return mixed anything that can be iterated
     */
    public function applyFilters();
    
    /**
     * @param RecursiveDirectoryIterator instance
     */
    public function setIterator($iterator);
}
