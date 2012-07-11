<?php
class Storage_Filesystem implements Storage_Interface
{
    /**
     *
     * @var Core_Engine
     */
    protected $_engine;
    /**
     * @var Output_Stack
     */
    protected $_out;
    /**
     * Identification of the object, it is the key from INI file
     *
     * @var string
     */
    protected $_identity;
    /**
     *
     * @var array
     */
    protected $_options;
    /** @var bool */
    protected $_asRemote;
    /** @var array */
    protected $_drivers;
    /**
     * Not sure if it is correct way to do it
     *
     * @var string
     */
    protected $_trimFromBegin;
    /**
     *
     * @var boolean 
     */
    protected $_isWindows;
    /**
     *
     * @var Storage_Filesystem_FileStat 
     */
    protected $_fileStat;

    /**
     * @param Core_Engine  $engine
     * @param Output_Stack $output
     * @param array        $options
     *
     * @return \Storage_Filesystem
     */
    public function  __construct($identity, $engine, $output, $options)
    {
        // merge options with default options
        Core_Engine::array_merge_defaults(
            $options,
            static::getConfigOptions(CfgPart::DEFAULTS),
            static::getConfigOptions(CfgPart::HINTS)
        );

        $this->_identity = $identity;
        $this->_out = $output;
        $this->_options = $options;
        $this->_engine = $engine;
        if (!array_key_exists('basedir', $this->_options)) {
            $this->_out->stop("parameter 'baseDir' is required by driver '{$this->_identity}'");
        }
        $this->_baseDir = $this->_options['basedir']; // store for faster access
        $this->_baseDir = rtrim($this->_baseDir, '/'). '/';
        $this->_isWindows = (strpos(strtolower(php_uname('s')), 'win') !== false);
        $this->_fileStat = new Storage_Filesystem_FileStat();
        if (array_key_exists('windows', $this->_options) && array_key_exists('encoding', $this->_options['windows'])) {
            $this->_fileStat->setWindowsEncoding($this->_options['windows']['encoding']);
        }
    }

    public function init($myrole, $drivers)
    {
        $this->_out->logNotice(">>>init ".get_class($this)." driver as $myrole");
        $this->_asRemote = $myrole==Core_Engine::ROLE_REMOTE;
        $this->_drivers = $drivers;

        if (!file_exists($this->_baseDir)) {
            $this->_out->stop("local folder {$this->_baseDir} doesn't exists");
        }

        return true;
    }

    public function refreshLocal($myrole, $drivers)
    {
        if ($this->_asRemote) {
            // nothing to do if I am not remote driver
            return;
        }

        $this->_out->logNotice(">>>refresh from local file system");
        
        if ($this->_isWindows) {
            $this->_refreshLocalWindows($myrole, $drivers);
        } else {
            $this->_refreshLocal($myrole, $drivers);
        }

    }
    
    protected function _refreshLocalWindows($myrole, $drivers)
    {
        $job = $this->_out->jobStart("traversing file system starting at {$this->_baseDir} using COM");
        //let create context for our closure
        /** @var $compare Compare_Interface */
        $compare = $drivers[Core_Engine::ROLE_COMPARE];
        $stat = $this->_fileStat;
        $baseDir = $this->_baseDir;
        $compare->updateFromLocalStart();
        $out = $this->_out;
        $root = true;
        
        $count = 0;
        $makePath = function($path) use (&$baseDir) {
            return str_replace("\\", "/", substr($path, strlen($baseDir)));
        };
        //another closure for compare
        $c = function($path) use (&$compare, &$stat, &$count, &$makePath)
        {
            /** @var $compare Compare_Interface */
            /** @var $stat Storage_Filesystem_FileStat */
            $obj = Core_FsObject::factoryFromStat($makePath($path), $stat->getStat($path));
            $compare->updateFromLocal($obj);
            $count++;
        };

        //currently regexp filter for windows is implemented in closure
        //for other platforms it should support Filter_Interface
        $options = $this->_options;
        $regExpFilter = function($name, $isDir) use (&$options) {
            // TODO fix notification
            $filterConf = @$options['filter'][$options['filterType']];
            
            if ($isDir) {
                if (isset($filterConf['config']['dirnameFilter'])) {
                    if (preg_match($filterConf['config']['dirnameFilter'], $name)) {
                        return false;
                    }
                }
            } else {
                if (isset($filterConf['config']['filenameFilter'])) {
                    if (preg_match($filterConf['config']['filenameFilter'], $name)) {
                        return false;
                    }
                }
            }
            return true;
        };
        //empty closure will help
        $empty = function ($folder) use (&$regExpFilter)
        {
            //fix: without it folder that containes only files excluded by filter
            //will not be created, but it should be created as empty folder
            if ($folder->Files->Count > 0 && $folder->SubFolders->Count == 0) {
                foreach ($folder->Files as $file) {
                    /** @var $file SplFileObject */
                    if ($regExpFilter($file->Name, false)) {
                        return false;
                    }
                }
                return true;
            }
            if($folder->Files->Count > 0 || $folder->SubFolders->Count > 0) {
                return false;
            }
            
            return true;
        };
        
        //this closure recursively iterates diretory, updating pathes with UTF8 support
        $magicScan = function($dir) use (&$c, &$baseDir, &$root, &$magicScan, &$empty, &$out, &$regExpFilter)
        {
            //initialize windows's FileSystemObject
            /** @noinspection PhpUndefinedClassInspection, PhpUndefinedConstantInspection */
            $fso = new COM('Scripting.FileSystemObject', null, CP_UTF8);
            
            if ($root) {
                $curDir = $baseDir;
                $root = false;
            } else {
                $curDir = $dir;
            }
            //excluding folders
            //see Scripting.FileSystemObject on http://msdn.microsoft.com
            /** @noinspection PhpUndefinedMethodInspection */
            $folder = $fso->GetFolder($curDir);
            foreach ($folder->Files as $file) {
                //excluding files
                if (!$regExpFilter($file->Name, false)) {
                    continue;
                }
                $c($file->Path);
            }
            foreach ($folder->SubFolders as $folder) {
                if (!$regExpFilter($folder->Name, true)) {
                     continue;
                }
                
                if($empty($folder)) {
                    //empty folders we update
                    $c($folder->Path.'/');
                } else {
                    //let's dig deeper :)
                    $magicScan($folder);
                }

            }
        };
        $magicScan($baseDir);
        $compare->updateFromLocalEnd();
        $this->_out->jobEnd($job, "done: updated info about $count files");
    }
    
    protected function _refreshLocal($myrole, $drivers)
    {
        
        $job = $this->_out->jobStart("traversing file system starting at {$this->_baseDir}");

        /* @var $compare Compare_Sqlite */
        $compare = $drivers[Core_Engine::ROLE_COMPARE];
        
        $stat = $this->_fileStat;
        $it = new RecursiveDirectoryIterator($this->_baseDir);
        $it->setFlags(RecursiveDirectoryIterator::SKIP_DOTS);
        
        //$it = new DirnameFilter($it, "/informa/i");
        if (isset($this->_options['filter'])) {
            $this->_out->logNotice(">>>applying filter ".$this->_options['filterType']);
            $filterConf = $this->_options['filter'][$this->_options['filterType']];
            /** @var $filter Filter_Interface */
            $filter = new $filterConf['class'] ($filterConf);
            $filter->setIterator($it);
            $it = $filter->applyFilters();
        }
        $itemCount = 0;
        $this->_trimFromBegin = strlen($this->_baseDir);
        $compare->updateFromLocalStart();
        foreach(new RecursiveIteratorIterator($it, RecursiveIteratorIterator::SELF_FIRST) as $file) {
            /** @var $file SplFileObject */
            //we don't need make entry for not empty folder
            if ($file->isDir() && !Storage_Filesystem_FileStat::isEmptyDir((string)$file))
            {
                continue;
            }
            $file = (string) $file;
            $obj = Core_FsObject::factoryFromStat($this->_makePath($file), $stat->getStat($file));
            $compare->updateFromLocal($obj);
            $itemCount++;
        }
        $compare->updateFromLocalEnd();

        $this->_out->jobEnd($job, "updated info about $itemCount files");
    }

    protected function _makePath($file)
    {
        return str_replace("\\", "/", substr($file, $this->_trimFromBegin));
    }
    
    public function getBaseDir()
    {
        return $this->_baseDir;
    }
    
    /**
     * 
     * 
     * @param string $path
     * @return string 
     */
    public function getMd5($path)
    {
        return $this->_fileStat->defineMd5($path);
    }
    
    public function isWindows()
    {
        return $this->_isWindows;
    }
    
    public function convertEncodingPath($path)
    {
        if ($this->_isWindows) {
            return mb_convert_encoding($path, $this->_options['windows']['encoding'], "auto");
        } else {
            return $path;
        }
    }

    static public function getConfigOptions($part=null)
    {
        $opt = array(
            CfgPart::DEFAULTS=>array(
                'refresh'=>false,
                // ??? 'filter'=>false,
                //'basedir'=>,
                'windows'=>array('encoding'=>'utf8'),

            ),
            CfgPart::HINTS=>array(
                'basedir'=>array(CfgPart::HINT_TYPE=>CfgPart::TYPE_PATH),
            ),
            CfgPart::DESCRIPTIONS=>array(
                'refresh'=>'read actual data about file system and feed compare driver ?',
                'basedir'=>'root directory to backup',
                'windows.encoding'=>'....',
            ),
            CfgPart::REQUIRED=>array('basedir')
        );

        if (is_null($part)) {
            return $opt;
        } else {
            return array_key_exists($part, $opt) ? $opt[$part] : array();
        }
    }
/*
    function refreshRemote($myrole, $drivers)
    {
        throw new Exception("refreshRemote not supported in Filesystem driver");
    }

    function updateRemote($myrole, $drivers)
    {
        throw new Exception("updateRemote not supported in Filesystem driver");
    }
*/
}
