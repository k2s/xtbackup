<?php
/**
 * Description of Lock
 *
 * @package    Xtbackup
 * @subpackage Core
 */
class Core_Lock 
{
    const TYPE_TMP_DIR = 1;
    const TYPE_TMP_DIR_MD5 = 2;
    
    protected static $_defaultLockName  = "xtbackup_lock_file.txt";
    
    protected $_lockType = self::TYPE_TMP_DIR_MD5;
    protected $_lockWait = 0;
    
    protected $_output;
    
    protected $_iniOptions;
    protected $_cmdOptions;
    
    protected $_iniMd5;
    
    protected $_savedMd5;
    
    protected $_locked = false;
    /**
     *
     * @var Resource 
     */
    protected $_file;
    
    public function __construct($iniOptions, $output) 
    {
        $this->_iniOptions = $iniOptions;
        $this->_cmdOptions = $iniOptions['ini'];
        $this->_output = $output;
        $this->initFileLock();
        $this->_output->logNotice(">>>Lock object was initialised");
    }
    
    
    public function setLockType($type)
    {
        $this->_lockType = $type;
        return $this;
    }
    
    public function initFileLock()
    {
        $this->_tmpDir = sys_get_temp_dir();
        $this->_iniMd5 = $this->_getIniMd5($this->_cmdOptions);
        
        if (isset($this->_iniOptions['engine']['lock-type'])) {
            $this->setLockType(intval($this->_iniOptions['engine']['lock-type']));
        } 
        
        if (isset($this->_iniOptions['engine']['lock-wait'])) {
            $this->_lockWait = intval($this->_iniOptions['engine']['lock-wait']);
        }
        $f = $this->_tmpDir."/".self::$_defaultLockName;
        $this->_file = self::_createFile($f);
        $this->_savedMd5 = $this->_getSavedMd5();
    }
        
    
    public function isLocked()
    {
        return $this->_locked;
    }
    
    protected function _lock($handle)
    {
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            $this->_locked = false;
        } else {
            $this->_locked = true;
            $this->_output->logNotice(">>>NOTICE! Failed to get lock");
        }
        
    }
    
    public function lock()
    {
        switch ($this->_lockType) {
            case self::TYPE_TMP_DIR:
                $this->_lock($this->_file);
                $this->_output->logNotice(">>>LOCK: lock type - NO compare ini md5");
                break;
            case self::TYPE_TMP_DIR_MD5:
            default :
                $this->_output->logNotice(">>>LOCK: lock type - also compare ini md5");
                //so we need to check current and saved md5
                $this->_output->logNotice($this->_iniMd5);
                var_dump($this->_savedMd5);
                
                if (empty($this->_savedMd5)|| (!empty($this->_savedMd5) && $this->_iniMd5 == $this->_savedMd5)) {
                    ftruncate($this->_file, 0);
                    fwrite($this->_file, $this->_iniMd5);
                    fflush($this->_file);
                    $this->_lock($this->_file);
                } else {
                    //simulate unlocked
                    return false;
                }
                
                break;
        }
        
        return $this->isLocked();
    }
    
    public function unlock()
    {
        if(!flock($this->_file, LOCK_UN))
        { 
            $this->_output->logNotice(">>>NOTICE! FAILED to release lock");
            return false;
        }
        ftruncate($this->_file, 0);
        fclose($this->_file);
        $this->_output->logNotice(">>>File unlocked");
    }
    
    public function wait()
    {
        if (false === $this->_lockWait) {
            exit("Application is locked!");
        } else {
            if (0 === $this->_lockWait) {
                $startTime = microtime();
                do { 
                    $canWrite = flock($this->_file, LOCK_EX);
                    if (!$canWrite) {
                        usleep(round(rand(0, 100)*1000));
                    }
                } while ((!$canWrite)and((microtime()-$startTime)) < 1000);
            } else {
                sleep(intval($this->_lockWait));
            }
        }
    }

    protected function _getIniMd5($cmdOptions)
    {
        $md5 = "";
        foreach ($cmdOptions as $option) {
            $md5 .= md5_file($option);
        }
            $md5 = md5($md5);
       return $md5;
    }
    
    protected static function _createFile($filename = null)
    {
        is_null($filename) ? $f = self::$_defaultLockName : $f = $filename;
        $handle = fopen($f, 'a+') or 
            die("Can't open file $f");
        return $handle;
    }
    
    protected function _getSavedMd5()
    {
        return fread($this->_file, 4096);
    }
    
    public function test() {
        return $this->_getSavedMd5();
    }
}

