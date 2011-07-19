<?php
/**
 * Description of Interface
 *
 * @package xtbackup
 */
interface Filter_Interface
{
    /**
     * Apply filters
     *
     * @return mixed anything that can be iterated
     */
    public function applyFilters();
    
    /**
     * Set iterator
     *
     * @param RecursiveDirectoryIterator $iterator instance
     *
     * @return void
     */
    public function setIterator($iterator);
}
