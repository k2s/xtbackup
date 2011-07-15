<?php
/**
 * Description of Abstract
 *
 * @author mamay
 */
class Filter_RegExp_Abstract extends RecursiveRegexIterator
{
    protected $regex;
    
    public function __construct(RecursiveIterator $it, $regex) {
        $this->regex = $regex;
        parent::__construct($it, $regex);
    }
}
