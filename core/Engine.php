<?php
require_once "core/StopException.php";
require_once "core/CfgPart.php";

// may be used in INI
!defined('ENGINE_DIR') && define("ENGINE_DIR", realpath(__DIR__ . '/../') . '/');

class Core_Engine
{
    /**
     * Major and minor version should be set here.
     * If revision number is question mark getVersion() will try to determine it from git.
     */
    const VERSION = "0.1.?";

    const ROLE_REMOTE = "remote";
    const ROLE_LOCAL = "local";
    const ROLE_COMPARE = "compare";
    /**
     * Cached final version of core engine
     *
     * @var string
     */
    protected static $_version = false;
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
     * Is running OS Windows ?
     *
     * @see isWindows()
     *
     * @var bool
     */
    protected static $_isWindows;
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
        // detect if OS is Windows
        self::$_isWindows = (strpos(strtolower(php_uname('s')), 'win') !== false);

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
        $this->_options = self::array_merge_recursive_distinct(
            $this->_optionsIni, $this->_optionsCmd
        );

        // add default options to engine
        self::array_merge_defaults(
            $this->_options['engine'],
            self::getConfigOptions(CfgPart::DEFAULTS),
            self::getConfigOptions(CfgPart::HINTS)
        );

        // initialize class autoloading
        $this->_initAutoload();
    }

    public function finish()
    {
        self::$out->logNotice("peak of memory usage: ".memory_get_peak_usage(true));
        self::$out->finish($this->_stopAt);
        if (false === $this->_stopAt) {
            return Core_StopException::RETCODE_OK;
        } else {
            return $this->_stopAt->getReturnCode();
        }
    }

    /**
     * Load INI file or lines into configuration array
     *
     * @param string|array $fileName INI file name or array of INI file line
     * @param bool $onlyReturn don't merge to $this->_optionsIni if true
     * @return array
     */
    public function loadIni($fileName, $onlyReturn = false)
    {
        if (false !== $this->_options) {
            // if init() already executed
            self::$out->stop("init() already executed, loadIni() is not allowed any more.");
            return array();
        }

        if (is_array($fileName)) {
            // we use parse_ini_string
            $params = @parse_ini_string(implode("\n", $fileName));
        } else {
            // load and parse INI file
            $params = @parse_ini_file($fileName);
        }
        if (false === $params) {
            $le = error_get_last();
            self::$out->stop($le['message']);
        }

        // process keys
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
        $params = array();
        for ($i = 1; $i < count($cmd); $i++) {
            $s = trim($cmd[$i]);
            if ($s[0]!='-') {
                // parameters which can't be passed in INI are excluded
                $params[] = $cmd[$i];
            }
        }
        $options = $this->loadIni($params, true);
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

    public static function array_merge_defaults(&$options, $defaults, $hints)
    {
        if (!is_array($options)) {
            $options = array();
        }
        foreach ($defaults as $key=>$val) {
            if (array_key_exists($key, $options)) {
                if (is_array($val)) {
                    if (!isset($hints[$key], $hints[$key][CfgPart::HINT_TYPE])) {
                        self::array_merge_defaults($options[$key], $defaults[$key], isset($hints[$key]) ? $hints[$key]:null);
                    }
                }
            } else {
                $options[$key] = $val;
            }
        }
    }

    public function listOptions($key = null)
    {
        if (is_null($key)) {

        }
    }

    protected function _configureOutput()
    {
        // construct configured outputs
        $outputs = array();
        if (isset($this->_options['engine']['outputs'])) {
            if (!is_array($this->_options['engine']['outputs'])) {
                // correct user mistake in configuration
                $this->_options['engine']['outputs'] = array($this->_options['engine']['outputs']);
            }
            foreach ($this->_options['engine']['outputs'] as $keyName) {
                $params = array_key_exists('output', $this->_options) && array_key_exists($keyName, $this->_options['output'])
                        ? $this->_options['output'][$keyName]
                        : array();
                $class = array_key_exists('class', $params) ? $params['class'] : $keyName;
                $class = "Output_" . ucfirst($class);
                $outputs[] = new $class($params);
            }
        }

        // replace current output
        self::$out->outputRemove();
        foreach ($outputs as $output) {
            self::$out->outputAdd($output);
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
        return new $class($key, $this, self::$out, $params);
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
        return new $class($key, $this, self::$out, $params);
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

            // engine.nice
            if (!self::isWindows() && false!==$this->_options['engine']['nice']) {
                self::$out->logNotice("changing process priority, nice=".$this->_options['engine']['nice']);
                proc_nice($this->_options['engine']['nice']);
            }

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
        require_once "output/Blackhole.php";
        require_once "output/Cli.php";
        require_once "compare/Interface.php";
        require_once "compare/Task.php";
        require_once "compare/Sqlite.php";
        require_once "storage/Interface.php";
        require_once "storage/S3.php";
        require_once "storage/Filesystem/FileStat.php";
        require_once "storage/Filesystem.php";
        require_once "filter/RegExp.php";
        require_once "storage/Mysql.php";

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

        try {
            $this->_runPhase("init", $orders)
            && $this->_runPhase("refreshLocal", $orders)
            && $this->_runPhase("refreshRemote", $orders)
            && $this->_runPhase("compare", $orders)
            && $this->_runPhase("updateRemote", $orders)
            && $this->_runPhase("shutdown", $orders);
        } catch (Core_StopException $e) {
            // error message
            $this->_stopAt = $e;
            self::$out->logError($e->getMessage());
            return false;
        } catch (Exception $e) {
            $myE = new Core_StopException("", "engine init", null, Core_StopException::RETCODE_FOREIGN_EXCEPTION);
            $myE->setException($e);
            $this->_stopAt = $myE;
            throw $e;
        }

        return true;
    }

    public function generateIni()
    {
        $classDefinition = array(
            CfgPart::REQUIRED => false,
            CfgPart::DESCRIPTIONS => "Driver class",
            CfgPart::SUGGESTED => false,
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
                        $ini[$type][] = "; REQUIRED";
                    }
                    if ($d[CfgPart::DESCRIPTIONS]) {
                        $ini[$type][] = "; ".str_replace("\n", "\n; ", $d[CfgPart::DESCRIPTIONS]);
                        $ini[$type][] = ";";
                    }
                    if (array_key_exists(CfgPart::DEFAULTS, $d)) {
                        //$ini[$type][] = "; default:";
                        $keyVal = $this->_renderKeyVal($cfg, $d[CfgPart::DEFAULTS], $d);
                        if (count($keyVal)) {
                            $ini[$type][] = "; default:";
                            foreach ($keyVal as $s) {
                                $ini[$type][] = ";     $s";
                            }
                        } else {
                            $ini[$type][] = "; default: $keyVal[0]";
                        }
                        $ini[$type][] = "";
                    }
                    if ($d[CfgPart::REQUIRED]) {
                        $ini[$type][] = "; $cfg = <enter your value and uncomment>";
                    } else {
                        if (array_key_exists('value', $d)) {
                            $keyVal = $this->_renderKeyVal($cfg, $d['value'], $d);
                            foreach ($keyVal as $s) {
                                if ($d[CfgPart::SUGGESTED]) {
                                    $ini[$type][] = "; see suggested section for values";
                                    $ini['suggested'][] = $s;
                                } else {
                                    $ini[$type][] = $s;
                                }
                            }
                        } else {
                            if ($d[CfgPart::SUGGESTED]) {
                                $ini[$type][] = "; see suggested section for values";
                                $ini['suggested'][] = "; $cfg = ";
                            } else {
                                $ini[$type][] = "; $cfg = ";
                            }
                        }
                    }
                    $ini[$type][] = "";

                }
            }
        }

        // put INI sections base on priority to resulting INI
        $priority = array(
            'suggested' => 'listed options are more often changed then other - see description later in this file',
            'engine' => "core engine configuration",
            'filter' => 'definition of filter(s)',
            'output' => 'configuration of output driver(s)',
            'storage' => 'definition of storage drivers',
            'compare' => 'definition of compare driver(s)'
        );
        $res = array();
        $res[] = "; xtbackup.php configuration file for version ".self::getVersion();
        $res[] = "";
        foreach ($priority as $section=>$description) {
            if (isset($ini[$section])) {
                $res[] = ";".str_repeat("*", strlen($description)+2);
                $res[] = "; $description";
                $res[] = "";
                $res[] = implode(PHP_EOL, $ini[$section]);
            }
        }

        return implode(PHP_EOL, $res);
    }

    protected function _renderKeyVal($cfg, $val, $def)
    {
        $type = isset($def['type']) ? $def['type'] : CfgPart::TYPE_UNKNOWN;
        if (CfgPart::TYPE_BOOL===$type) {
            if (!is_string($val)) {
                $val = $val ? "true" : "false";
            }
        }

        $ret = array();
        if (is_array($val)) {
            if (count($val)>0) {
                /** @noinspection PhpWrongForeachArgumentTypeInspection */
                foreach ($val as $v) {
                    $ret[] = "$cfg = $v";
                }
            } else {
                $ret[] = "$cfg =";
            }
        } else {
            $ret[] = "$cfg = $val";
        }
        return $ret;
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
        !isset($options[CfgPart::SUGGESTED]) && $options[CfgPart::SUGGESTED] = array();

        // build full list of options
        $keys = array_keys($options[CfgPart::DEFAULTS])
                + array_keys($options[CfgPart::HINTS])
                + array_keys($options[CfgPart::DESCRIPTIONS])
                + array_keys($options[CfgPart::REQUIRED])
                + array_keys($options[CfgPart::SUGGESTED]);

        // define each parameter
        $params = array();
        foreach ($keys as $key) {
            $params[$key] = array(
                CfgPart::DESCRIPTIONS=>isset($options[CfgPart::DESCRIPTIONS][$key]) ? $options[CfgPart::DESCRIPTIONS][$key] : null,
                CfgPart::REQUIRED=>isset($options[CfgPart::REQUIRED][$key]) ? $options[CfgPart::REQUIRED][$key] : false,
                CfgPart::SUGGESTED=>isset($options[CfgPart::SUGGESTED][$key]) ? $options[CfgPart::SUGGESTED][$key] : false,
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

    /**
     * Return current version of core engine
     *
     * @static
     * @return string
     */
    static public function getVersion()
    {
        if (false===self::$_version) {
            $version = self::VERSION;
            if ("?"==substr($version, -1)) {
                // try to determine revision number from git
                self::$_version = substr($version, 0, -1) . self::getRevisionFromGit(ENGINE_DIR);
            }
        }

        return self::$_version;
    }

    static public function isWindows()
    {
        return self::$_isWindows;
    }

    /**
     * Obtain information about current git checkout
     *
     * It is useful also for extensions.
     *
     * @static
     * @param string $path path where to look for version
     * @return string revision description
     */
    static public function getRevisionFromGit($path)
    {
        // TODO we need to study git more and build this method
        return "UNKNOWN";
    }

    static public function getConfigOptions($part = null)
    {
        $opt = array(
            CfgPart::VERSION => self::getVersion(),
            CfgPart::HINTS =>array(
                'extensions' => array(CfgPart::HINT_TYPE=>CfgPart::TYPE_ARRAY),
                'outputs' => array(CfgPart::HINT_TYPE=>CfgPart::TYPE_ARRAY),
            ),
            CfgPart::DEFAULTS => array(
                'extensions' => array(),
                'outputs' => array('cli'),
                'nice' => false,
            ),
            CfgPart::DESCRIPTIONS => array(
                'outputs' => <<<TXT
Reference output configuration key(s) which will be used to process output from engine.
You may configure multiple output configurations to be used by engine.

Example: engine.outputs[] = mycli
TXT
                ,
                'compare' => <<<TXT
Reference compare configuration key which will be used to evaluate what files have to be backuped.
TXT
                ,
                'remote' => <<<TXT
Reference storage configuration key which will be used as remote storage.
Local storage will be backuped to remote storage.

Example: engine.remote = mys3
TXT
                ,
                'local' => <<<TXT
Reference storage configuration key which will be used as local storage.
Local storage will be backuped to remote storage.

Example: engine.local = myfilesystem
TXT
                ,
                'extensions' => <<<TXT
Register directories with additional drivers.
Structure of such directory has to follow xtbackup folder hierarchy.
It is possible to use ENGINE_DIR constant in INI, which points to parent folder of core/Engine.php.

Example:
engine.extensions[] = ENGINE_DIR "/examples/plugins"
TXT
            ,
            'nice' => <<<TXT
Set process priority. Doesn't work on Windows.
See PHP/proc_nice and `man nice` for more info.
TXT

            ),
            CfgPart::REQUIRED => array('local'=>true, 'remote'=>true, 'compare'=>true),
            CfgPart::SUGGESTED =>array('outputs'=>true),
        );

        if (is_null($part)) {
            return $opt;
        } else {
            return isset($opt[$part]) ? $opt[$part] : array();
        }
    }
}