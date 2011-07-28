<?php
require_once "core/CfgPart.php";

// may be used in INI
!defined('ENGINE_DIR') && define("ENGINE_DIR", realpath(__DIR__ . '/../') . '/');

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

class Core_Engine
{
    const ROLE_REMOTE = "remote";
    const ROLE_LOCAL = "local";
    const ROLE_COMPARE = "compare";
    /**
     * Output object, used to do logging and progress visualization tasks
     *
     * @var Output_Stack
     */
    public static $out;
    /**
     * Configuration options comming from command line
     *
     * @var array
     */
    protected $_optionsCmd = array();
    /**
     * Config options loaded from INI files
     *
     * @var array
     */
    protected $_optionsIni = array();
    /**
     * Configuration options used to execute backup task
     *
     * It is merge of $_optionsIni and $_optionsCmd
     *
     * @var array
     */
    protected $_options = false;
    /**
     * Help message configurable from main application
     * @see setAppHelpMessage
     *
     * @var string
     */
    protected $_appHelpMessage = false;
    /**
     * Reference to drivers with different roles
     *
     * @var array
     */
    protected $_roles = array();

    /**
     * @TODO Idea is to be able to store different backups in same compare DB
     *
     * @var bool
     */
    protected $_uniqueKey = false;

    /**
     * Signals that execution stopped at some point ff different from FALSE
     * False if no problem.
     *
     * @var Core_StopException
     */
    protected $_stopAt = false;

    /**
     * Constructor
     *
     * The $output object is used already before INI is loaded so it is important object to see errors in configuration.
     *
     * @param array $cmdArguments
     * @param Output_Interface $output default logging object, if null Output_Cli will be used, if FALSE no output will happen
     *
     * @return \Core_Engine
     */
    public function __construct($cmdArguments = array(), $output = null)
    {
        // we need to have at least output class as soon as possible
        require_once 'output/Interface.php';
        require_once 'output/Stack.php';
        self::$out = new Output_Stack();
        if (false!==$output) {
            if (!is_null($output)) {
                // output was passed
                self::$out->outputAdd($output);
            } else {
                // add Output_Cli as default output for startup
                require_once 'output/Cli.php';
                self::$out->outputAdd(new Output_Cli(array()));
            }
        }

        // process configuration arguments
        $this->_optionsCmd = $this->_loadConfigFromArguments($cmdArguments);

        // load configuration from config files
        $this->_loadConfigFiles();

        // prepare final options
        $this->_options = self::array_merge_recursive_distinct(self::getConfigOptions(CfgPart::DEFAULTS), $this->_optionsIni, $this->_optionsCmd);

        // initialize class autoloading
        $this->_initAutoload();
    }

    public function finish()
    {
        self::$out->finish($this->_stopAt);
        if (false === $this->_stopAt) {
            return Core_StopException::RETCODE_OK;
        } else {
            return $this->_stopAt->getReturnCode();
        }
    }

    public function loadIni($fileName, $onlyReturn = false)
    {
        if (false !== $this->_options) {
            // if init() already executed
            self::$out->stop("init() already executed, loadIni() is not allowed any more.");
            return array();
        }

        $params = @parse_ini_file($fileName);
        if (false === $params) {
            $le = error_get_last();
            self::$out->stop($le['message']);
        }

        $options = array();
        foreach ($params as $key => $val) {
            $a = explode(".", $key);
            $param = array();
            $b = &$param;
            foreach ($a as $k) {
                $b[$k] = array();
                $b = &$b[$k];
            }
            $b = $val;

            $options = array_merge_recursive($options, $param);
        }

        if (!$onlyReturn) {
            // merge with existing options
            $this->_optionsIni = array_merge_recursive($this->_optionsIni, $options);
        }

        return $options;
    }

    protected function _loadConfigFiles()
    {
        if (!isset($this->_optionsCmd['ini'])) {
            return;
        }

        foreach ($this->_optionsCmd['ini'] as $fileName) {
            $this->loadIni($fileName);
        }

        // overide loaded settings with settings from command line
        //$this->_options = self::array_merge_recursive_distinct($this->_options, $options);
    }

    protected function _loadConfigFromArguments($cmd)
    {
        // TODO change this code to build INI string and load it with loadIni()
        $options = array();
        for ($i = 1; $i < count($cmd); $i++) {
            // clean up param name and value
            $a = explode("=", $cmd[$i], 2);
            $param = trim($a[0]);
            $param = ltrim($param, '-');
            if (substr($param, -2) == "[]") {
                $isArray = true;
                $param = substr($param, 0, -2);
            } else {
                $isArray = false;
            }
            if (count($a) > 1) {
                $val = trim($a[1], ' "');
            } else {
                $val = null;
            }
            // change param name into nested array
            $a = explode(".", $param);
            $param = array();
            $b = &$param;
            foreach ($a as $k) {
                $b[$k] = array();
                $b = &$b[$k];
            }
            if ($isArray) {
                $b[] = $val;
            } else {
                $b = $val;
            }
            // store in options
            $options = self::array_merge_recursive_distinct($options, $param);
        }
        return $options;
    }

    /**
     * Merges any number of arrays / parameters recursively, replacing
     * entries with string keys with values from latter arrays.
     * If the entry or the next value to be assigned is an array, then it
     * automagically treats both arguments as an array.
     * Numeric entries are appended, not replaced, but only if they are
     * unique
     *
     * calling: result = array_merge_recursive_distinct(a1, a2, ... aN)
     *
     * @return array
     **/
    public static function array_merge_recursive_distinct()
    {
        $arrays = func_get_args();
        $base = array_shift($arrays);
        if (!is_array($base)) $base = empty($base) ? array() : array($base);
        foreach ($arrays as $append) {
            if (!is_array($append)) $append = array($append);
            foreach ($append as $key => $value) {
                if (!array_key_exists($key, $base) and !is_numeric($key)) {
                    $base[$key] = $append[$key];
                    continue;
                }
                if (isset($base[$key]) && is_array($base[$key])) {
                    $base[$key] = self::array_merge_recursive_distinct($base[$key], $append[$key]);
                } else if (is_numeric($key) /*&& is_array($value)*/) {
                    if (!in_array($value, $base)) $base[] = $value;
                } else {
                    $base[$key] = $value;
                }
            }
        }
        return $base;
    }

    public function listOptions($key = null)
    {
        if (is_null($key)) {

        }
    }

    protected function _configureOutput()
    {
        // remove default output driver
        self::$out->outputRemove();

        // add configured outputs
        if (isset($this->_options['engine']['outputs'])) {
            if (!is_array($this->_options['engine']['outputs'])) {
                // correct user mistake in configuration
                $this->_options['engine']['outputs'] = array($this->_options['engine']['outputs']);
            }
            foreach ($this->_options['engine']['outputs'] as $keyName) {
                $params = array_key_exists('outputs', $this->_options) && array_key_exists($keyName, $this->_options['outputs'])
                        ? $this->_options['outputs'][$keyName]
                        : array();
                $class = array_key_exists('class', $params) ? $params['class'] : $keyName;
                $class = "Output_" . ucfirst($class);
                self::$out->outputAdd(new $class($params));
            }
        }

        // first output
        self::$out->welcome();
    }

    protected function _getCompare($key)
    {
        if (!isset($this->_options['compare']) || !isset($this->_options['compare'][$key])) {
            self::$out->stop("Definition for compare driver '$key' not found.");
        }

        $params = $this->_options['compare'][$key];
        if (isset($params['class'])) {
            $class = "Compare_" . ucfirst($params['class']);
        } else {
            $class = "Compare_" . ucfirst($key);
        }
        self::$out->logDebug("configuring compare driver '$class' from key '$key'");
        return new $class($this, self::$out, $params);
    }

    protected function _getStorage($key)
    {
        if (!isset($this->_options['storage'])) {
            self::$out->stop("Definition for storage driver '$key' not found.");
        }

        $params = $this->_options['storage'][$key];
        if (isset($params['class'])) {
            $class = "Storage_" . ucfirst($params['class']);
        } else {
            $class = "Storage_" . ucfirst($key);
        }
        self::$out->logDebug("configuring storage driver '$class' from key '$key'");
        return new $class($this, self::$out, $params);
    }

    protected function _configureLocalStorage()
    {
        if (!isset($this->_options['engine']['local'])) {
            throw new Core_StopException("You have to configure `engine.compare`.", "configureLocalStorage");
        }
        $key = $this->_options['engine']['local'];
        self::$out->logDebug("local storage will be configured based on key '$key'");
        $this->_roles[self::ROLE_LOCAL] = $this->_getStorage($key);
    }

    protected function _configureRemoteStorage()
    {
        if (!isset($this->_options['engine']['remote'])) {
            throw new Core_StopException("You have to configure `engine.remote`.", "configureRemoteStorage");
        }
        $key = $this->_options['engine']['remote'];
        self::$out->logDebug("remote storage will be configured based on key '$key'");
        $this->_roles[self::ROLE_REMOTE] = $this->_getStorage($key);
    }

    protected function _configureCompare()
    {
        if (!isset($this->_options['engine']['compare'])) {
            throw new Core_StopException("You have to configure `engine.compare`.", "configureCompare");
        }
        $key = $this->_options['engine']['compare'];
        self::$out->logDebug("compare driver will be configured based on key '$key'");
        $this->_roles[self::ROLE_COMPARE] = $this->_getCompare($key);
    }

    public function init()
    {
        try {
            if (!isset($this->_options['engine'])) {
                throw new Core_StopException("You have to configure `engine`.", "init");
            }

            // configure output
            $this->_configureOutput();

            // configure compare driver
            $this->_configureCompare();

            // configure local driver
            $this->_configureLocalStorage();

            // configure remote driver
            $this->_configureRemoteStorage();
        } catch (Core_StopException $e) {
            // start text
            self::$out->welcome();
            // error message
            $this->_stopAt = $e;
            self::$out->logError($e->getMessage());
            // help instructions
            self::$out->showHelp($this->_appHelpMessage);
            return false;
        } catch (Exception $e) {
            $myE = new Core_StopException("", "engine init", null, Core_StopException::RETCODE_FOREIGN_EXCEPTION);
            $myE->setException($e);
            $this->_stopAt = $myE;
            throw $e;
        }

        return true;
    }

    public function setAppHelpMessage($helpMessage)
    {
        $this->_appHelpMessage = $helpMessage;
    }

    /**
     * @return void
     */
    protected function _initAutoload()
    {
        self::$out->logDebug("initializing class autoloading");

        // load default classes
        require_once "core/FsObject.php";
        require_once "output/Empty.php";
        require_once "output/Cli.php";
        require_once "compare/Interface.php";
        require_once "compare/Task.php";
        require_once "compare/Sqlite.php";
        require_once "storage/Interface.php";
        require_once "storage/S3.php";
        require_once "storage/Filesystem/FileStat.php";
        require_once "storage/Filesystem.php";
        require_once "filter/RegExp.php";

        // load classes from extensions
        foreach ($this->_options['engine']['extensions'] as $path) {
            if (file_exists($path . '/_init.php')) {
                self::$out->logNotice("extending functionality with drivers from '$path'");
                /** @noinspection PhpIncludeInspection */
                require_once $path . '/_init.php';
            } else {
                self::$out->logWarning("no extension found in '$path' (_init.php missing)");
            }
        }
    }

    public function getUniqueKey()
    {
        if (!$this->_uniqueKey) {
            // TODO calculate based on $this->_roles
            $this->_uniqueKey = "test";
        }

        return $this->_uniqueKey;
    }

    protected function _runPhase($phase, $order)
    {
        self::$out->logNotice("phase start: $phase");
        self::$out->mark();
        foreach ($order as $role => $driver)
        {
            if (method_exists($driver, $phase)) {
                $driver->$phase($role, $this->_roles);
            }
        }
        $sec = self::$out->time();
        self::$out->logNotice("phase $phase executed in $sec seconds");
        return true;
    }

    public function run()
    {
        if ($this->_stopAt !== false) {
            return false;
        }

        $orders = $this->_roles;

        $this->_runPhase("init", $orders)
        && $this->_runPhase("refreshLocal", $orders)
        && $this->_runPhase("refreshRemote", $orders)
        && $this->_runPhase("compare", $orders)
        && $this->_runPhase("updateRemote", $orders)
        && $this->_runPhase("shutdown", $orders);

        return true;
    }

    public function generateIni()
    {
        $classDefinition = array(
            CfgPart::REQUIRED => false,
            CfgPart::DESCRIPTIONS => "Driver class",
        );
        $driver = array(
            'engine' => array('engine' => self::compactConfig(self::getConfigOptions())),
            'storage' => array(),
            'filter' => array(),
            'compare' => array(),
            'output' => array(),
        );
        // request getConfigOptions on all drivers
        foreach (get_declared_classes() as $className) {
            $implements = array_intersect(
                class_implements($className),
                array('Storage_Interface', 'Output_Interface', 'Filter_Interface', 'Compare_Interface')
            );
            foreach ($implements as $s) {
                $s = strtolower(substr($s, 0, -10));
                $driver[$s][$className] = self::compactConfig(call_user_func(array($className, 'getConfigOptions')));
            }
        }

        // render INI sections
        $ini = array();
        foreach ($driver as $type => $defs) {
            $ini[$type] = array();
            foreach ($defs as $class => $options) {
                // build the full config key
                $classCfg = array();
                $key = $class;
                if ($type!=="engine") {
                    // only engine does not have prefix
                    $classCfg[] = $type;
                    $key = strtolower($key);
                    if (strpos($key, $type."_")===0) {
                        $key =  substr($key, strlen($type)+1);
                    }
                    // we want to show that class does not need to be the key
                    $key = "my".$key;
                    //array_unshift($options, array("class"=>array_merge($classDefinition, array('value'=>$class))));
                    $options = array("class"=>$classDefinition + array('value'=>$class)) + $options;
                }
                $classCfg[] = $key;

                // handle options
                foreach ($options as $option=>$d) {
                    if (isset($d[CfgPart::HINT_TYPE]) && $d[CfgPart::HINT_TYPE]==CfgPart::TYPE_ARRAY) {
                        $option .= "[]";
                    }
                    $cfg = implode(".", array_merge($classCfg, array($option)));

                    // render INI lines
                    if ($d[CfgPart::REQUIRED]) {
                        $ini[$type][] = "; !!!";
                    }
                    if ($d[CfgPart::DESCRIPTIONS]) {
                        $ini[$type][] = "; ".str_replace("\n", "\n; ", $d[CfgPart::DESCRIPTIONS]);
                        $ini[$type][] = ";";
                    }
                    if (array_key_exists(CfgPart::DEFAULTS, $d)) {
                        //$ini[$type][] = "; default:";
                        $ini[$type][] = "; default: $cfg = " . $d[CfgPart::DEFAULTS];
                    }
                    if ($d[CfgPart::REQUIRED]) {
                        $ini[$type][] = "$cfg = <enter your value>";
                    } else {
                        if (array_key_exists("value", $d)) {
                            $ini[$type][] = "$cfg = " . $d['value'];
                        } else {
                            $ini[$type][] = "; $cfg = ";
                        }
                    }
                    $ini[$type][] = "";
                }
            }
        }

        // put INI sections base on priority to resulting INI
        $priority = array('engine', '_cmds', 'filter', 'output', 'storage', 'compare');
        $res = array();
        foreach ($priority as $section) {
            if (isset($ini[$section])) {
                $res[] = implode(PHP_EOL, $ini[$section]);
            }
        }

        return implode(PHP_EOL, $res);
    }

    static public function compactConfig($options)
    {
        if (!is_array($options)) {
            return array();
        }

        // add missing parts
        !isset($options[CfgPart::DEFAULTS]) && $options[CfgPart::DEFAULTS] = array();
        !isset($options[CfgPart::DESCRIPTIONS]) && $options[CfgPart::DESCRIPTIONS] = array();
        !isset($options[CfgPart::REQUIRED]) && $options[CfgPart::REQUIRED] = array();
        !isset($options[CfgPart::HINTS]) && $options[CfgPart::HINTS] = array();

        // build full list of options
        $keys = array_keys($options[CfgPart::DEFAULTS])
                + array_keys($options[CfgPart::HINTS])
                + array_keys($options[CfgPart::DESCRIPTIONS])
                + array_keys($options[CfgPart::REQUIRED]);

        // define each parameter
        $params = array();
        foreach ($keys as $key) {
            $params[$key] = array(
                CfgPart::DESCRIPTIONS=>isset($options[CfgPart::DESCRIPTIONS][$key]) ? $options[CfgPart::DESCRIPTIONS][$key] : null,
                CfgPart::REQUIRED=>isset($options[CfgPart::REQUIRED][$key]) ? $options[CfgPart::REQUIRED][$key] : false,
            );
            // we have to left out default if it is not defined, to be able to detect that there is no default
            if (array_key_exists($key, $options[CfgPart::DEFAULTS])) {
                $params[$key][CfgPart::DEFAULTS] = $options[CfgPart::DEFAULTS][$key];
            }
            // add hints
            if (array_key_exists($key, $options[CfgPart::HINTS])) {
                foreach ($options[CfgPart::HINTS][$key] as $hint=>$def) {
                    $params[$key][$hint] = $def;
                }
            }
        }

        return $params;
    }

    static public function getConfigOptions($part = null)
    {
        $opt = array(
            CfgPart::HINTS =>array(
                'extensions' => array(CfgPart::HINT_TYPE=>CfgPart::TYPE_ARRAY),
                'outputs' => array(CfgPart::HINT_TYPE=>CfgPart::TYPE_ARRAY),
            ),
            CfgPart::DEFAULTS => array(
                'extensions' => array(),
            ),
            CfgPart::DESCRIPTIONS => array(
                'outputs' => <<<TXT
You may configure multiple output configurations to be used by engine.
TXT
                ,
                'compare' => <<<TXT
Configure ...
TXT
                ,
                'remote' => <<<TXT
Configure ...
TXT
                ,
                'local' => <<<TXT
Configure ...
TXT
                ,
                'extensions' => <<<TXT
Register directories with additional drivers.
Structure of such directory has to follow xtbackup folder hierarchy.
It is possible to use ENGINE_DIR constant in INI, which points to parent folder of core/Engine.php.

Example:
engine.extensions[] = ENGINE_DIR "/examples/plugins"
TXT
            ),
            CfgPart::REQUIRED => array('local'=>true, 'remote'=>true, 'compare'=>true)
        );

        if (is_null($part)) {
            return $opt;
        } else {
            return isset($opt[$part]) ? $opt[$part] : array();
        }
    }
}