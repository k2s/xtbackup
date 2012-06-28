<?php
/**
 * Throw this exception to stop application execution
 *
 * Contains additional information for user output.
 */
class Core_StopException extends Exception
{
    const RETCODE_OK = 0;
    const RETCODE_FOREIGN_EXCEPTION = 1;
    const RETCODE_EXCEPTION = 2;

    /**
     * @var string
     */
    protected $_stopAt;
    /**
     * @var int
     */
    protected $_retCode;
    /**
     * @var bool|Exception
     */
    protected $_foreignException = false;

    public function __construct($message, $stopAt, $previous = null, $retCode = self::RETCODE_EXCEPTION)
    {
        $this->_stopAt = $stopAt;
        $this->_retCode = $retCode;
        parent::__construct($message, 0, $previous);
    }

    public function setException($e)
    {
        $this->_foreignException = $e;
    }

    public function getException()
    {
        return false === $this->_foreignException ? $this : $this->_foreignException;
    }

    public function getStopAt()
    {
        return $this->_stopAt;
    }

    public function getReturnCode()
    {
        return $this->_retCode;
    }
}