<?php
require_once "lib/AWSSDKforPHP/sdk.class.php";

class Storage_S3 implements Storage_Interface
{
    //both used in _getPathWithBasedir
    const ADD_BASE_DIR = 1;
    const REMOVE_BASE_DIR = 2;

    /**
     * @var AmazonS3
     */
    protected $_s3;
    /**
     * Keep information if versioning is enabled on S3 bucket
     * @var bool
     */
    protected $_versioningEnabled;
    /**
     *
     * @var Core_Engine
     */
    protected $_engine;
    /**
     * @var Output_Stack
     */
    protected $_out;

    protected $_defaultRedundancyStorage = AmazonS3::STORAGE_STANDARD;
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
     * We need adjusted basedir not the original one form configuration
     *
     * @var string
     */
    protected $_baseDir = null;

    protected $_itemCount = 0;

    /**
     * @param Core_Engine $engine
     * @param Output_Stack $output
     * @param array $options
     *
     * @return \Storage_S3
     */
    public function  __construct($identity, $engine, $output, $options)
    {
        // merge options with default options
        Core_Engine::array_merge_defaults(
            $options,
            static::getConfigOptions(CfgPart::DEFAULTS),
            static::getConfigOptions(CfgPart::HINTS)
        );

        // TODO see Compare_Sqlite constructor

        $this->_identity = $identity;
        $this->_out = $output;
        $this->_options = $options;
        $this->_engine = $engine;
        //init basedir property
        $this->getBaseDir();
    }

    public function init($myrole, $drivers)
    {
        $this->_out->logNotice(">>>init S3 driver as $myrole");
        $this->_asRemote = $myrole == Core_Engine::ROLE_REMOTE;

        // Amazon library SSL Connection Issues
        if (!defined('AWS_CERTIFICATE_AUTHORITY')) {
            define('AWS_CERTIFICATE_AUTHORITY', $this->_options['certificate_authority']);
        } else {
            $this->_out->logNotice("option 'certificate_authority' was already set, it can't be changed");
        }

        if ($this->_options['compatibility-test']) {
            // see lib/AWSSDKforPHP/_compatibility_test
            $this->_out->jobStart("executing Amazon SDK compatibility test");
            // their code shows notices
            error_reporting(E_ALL & ~E_NOTICE);
            include "lib/AWSSDKforPHP/_compatibility_test/sdk_compatibility_test_cli.php";
            $this->_out->stop("-- re-run without --");
        }

        // test parameters
        if (!isset($this->_options['key'], $this->_options['key']['access'])) {
            throw new Core_StopException("You have to define S3 option key.access.", "S3Init");
        }

        if (!isset($this->_options['key']['secret'])) {
            throw new Core_StopException("You have to define S3 option key.secret.", "S3Init");
        }
        if (!is_null($this->_options['multipart']['part-size']) && ($this->_options['multipart']['part-size'] < 5 || $this->_options['multipart']['part-size'] > 5120)) {
            throw new Core_StopException(
                "multipart.part-size has to be in range from 5MB to 500MB. It is Amazon S3 restriction. Current value is {$this->_options['multipart']['part-size']}MB.",
                "S3Init"
            );
        }

        $job = $this->_out->jobStart("handshaking with Amazon S3");
        // TODO we need better AmazonS3 error handling
        $this->_s3 = new AmazonS3(
            array(
                'key' => $this->_options['key']['access'],
                'secret' => $this->_options['key']['secret']
            )
        );
        if (false == $this->_s3->if_bucket_exists($this->getBucket())) {
            $this->_out->jobEnd($job, "failed");
            throw new Core_StopException("S3 bucket not found: '{$this->getBucket()}' for access key '" . substr($this->_options['key']['access'], 0, 5) . "...'", "S3Init");
        }
        $this->_out->jobEnd($job, "authorized");

        // find out if versioning is enabled
        $versioning = $this->_s3->get_versioning_status($this->getBucket());
        if (!$versioning->isOK()) {
            throw new Core_StopException("Not possible to get versioning status of S3 bucket. (" . (string)$versioning->body->Code . ": " . (string)$versioning->body->Message . ")", "S3Init");
        }
        $this->_versioningEnabled = $versioning->body->Status == "Enabled";
        if (!$this->_versioningEnabled) {
            $priority = $this->_options['warn-versioning'] ? Output_Stack::WARNING : Output_Stack::DEBUG;
            $this->_out->log(
                $priority,
                "Versioning not enabled for this S3 bucket, you will not be able to restore older versions of files."
            );
        }
        if (array_key_exists('defaultRedundancyStorage', $this->_options)) {
            if (is_string($this->_options['defaultRedundancyStorage'])) {
                $this->_defaultRedundancyStorage = constant("AmazonS3::" . $this->_options['defaultRedundancyStorage']);
            }
        }
        return true;
    }

    public function refreshRemote($myrole, $drivers)
    {
        if (!$this->_asRemote) {
            // nothing to do if I am not remote driver
            return;
        }

        $this->_out->logNotice(">>>refresh from remote S3 driver");

        /* @var $compare Compare_Sqlite */
        $compare = $drivers[Core_Engine::ROLE_COMPARE];

        if ($this->_options['refresh'] == "never" || ((int)$this->_options['refresh'] !== 1 && $this->_options['refresh'] !== true)) {
            // we are advised not to refresh
            $wasUpdated = $compare->wasAlreadyUpdatedFrom($myrole);
            if ($this->_options['refresh'] == "never") {
                // we are forced not to refresh
                if ($wasUpdated) {
                    $this->_out->logNotice("skipped, forbidden by user");
                } else {
                    $this->_out->logWarning("skipped, forbidden by user, but WAS NEVER UPDATED BEFORE");
                }
                // don't continue
                return;
            } elseif ($wasUpdated) {
                $this->_out->logNotice("skipped, not requested and not needed");
                return;
            } else {
                $this->_out->logNotice("not requested, but was never updated before so we will do now");
            }
        } else {
            $this->_out->logNotice("update requested by user");
        }

        //we do not suppose to enable this functionality
        //$this->changeRedundancy();

        $job = $this->_out->jobStart("downloading info about files stored in Amazon S3");
        $this->_out->jobSetProgressStep($job, 100);

        // let compare driver know that we are starting
        $compare->updateFromRemoteStart();

        $this->_list(array($this, "_refreshRemote"), array('compare' => $compare, 'job' => $job));
        // let compare driver know that we are done
        $compare->updateFromRemoteEnd();

        $this->_out->jobEnd($job, "downloaded info about {$this->_itemCount} files");
    }

    /**
     * lists bucket's objects, applying callback to each of them
     *
     * @param mixed $callback first argument of the callback is CFSimpleXML object
     * @param array $params
     */
    protected function _list($callback, $params = array())
    {
        // prepare data for loop
        $bucket = $this->getBucket();
        $baseDir = $this->getBaseDir();
        $marker = '';
        $itemCount = 0;
        $v = false;

        $firstBatch = true;
        do {
            $list = $this->_s3->list_objects(
                $bucket,
                array(
                    'marker' => $marker,
                    'prefix' => $baseDir,
                )
            );

            if (!is_object($list->body->Contents)) {
                $this->_out->stop("S3 response problem, no content returned");
            }

            $count = $list->body->Contents->count();
            if ($count === 0) {
                if ($firstBatch) {
                    break;
                } else {
                    $this->_out->stop("S3 response problem, not all files returned");
                }
            }
            $this->_itemCount += $count;

            $jobFiles = $this->_out->jobStart("processing information about $count remote files");

            // download meta data
//            $batch = new CFBatchRequest(3);
//            foreach ($list->body->Contents as $v) {
//                /** @noinspection PhpUndefinedMethodInspection */
//                $this->_s3->batch($batch)->get_object_headers($bucket, $v->Key); // Get content-type
//        }
//            /** @var $response CFArray */
//            $response = $this->_s3->batch($batch)->send();
//            if (!$response->areOK()) {
//                $this->_out->stop("S3 response problem, meta data not returned");
//            }
//            if (count($response) != $count) {
//                $this->_out->stop("S3 response problem, meta data not returned for all files");
//            }

            // process received information
            $metaId = 0;
            foreach ($list->body->Contents as $v) {
                switch (true) {
                    case is_array($callback):
                    case is_string($callback):
                        call_user_func($callback, $v, $params);
                        break;
                    case is_callable($callback):
                        /** @var $callback Closure */
                        $callback($v, $params);
                        break;
                }
            }
            $this->_out->jobEnd($jobFiles, "updated info about one batch of files");

            // move to next batch of files
            $marker = $v->Key;
            $firstBatch = false;
        } while ((string)$list->body->IsTruncated == 'true');

    }

    protected function _refreshRemote($v, $params)
    {
        $fsObject = $this->_createFsObject($v, null);
        $fsObject->path = $this->_getPathWithBasedir($fsObject->path);
        $params['compare']->updateFromRemote($fsObject);

        // TODO update progress, needs better clarificatio
        $this->_out->jobStep($params['job']);
    }

    /**
     *
     * @param CFResponse $v
     * @param CFResponse $meta
     *
     * @return Core_FsObject
     */
    public function _createFsObject($v, $meta = null)
    {
        $path = (string)$v->Key;

        // $path ends with '/' or $path ends with '_$folder$'
        $isDir = ('/' == substr($path, -1)) || ('_$folder$' == substr($path, 9));
        $obj = new Core_FsObject($path, $isDir, (float)$v->Size, (string)$v->LastModified, str_replace('"', '', (string)$v->ETag));
        //$obj->setLastSyncWithLocal(isset($meta->header['x-amz-meta-localts']) ? $meta->header['x-amz-meta-localts'] : null);

        return $obj;
    }

    public function getBucket()
    {
        return $this->_options['bucket'];
    }

    public function getBaseDir()
    {
        if (is_null($this->_baseDir)) {
            // TODO we should adjust baseDir in constructor and remove getBaseDir()
            if (isset($this->_options['basedir'])) {
                $this->_baseDir = trim($this->_options['basedir']);
                if (strlen($this->_baseDir) > 0 && substr($this->_baseDir, -1) != '/') {
                    // the base dir has to end with slash
                    $this->_baseDir .= "/";
                }
            } else {
                $this->_baseDir = "";
            }
        }
        return $this->_baseDir;
    }

    public function updateRemote($myrole, $drivers)
    {
        if ($this->_options['update'] == 'simulate') {
            $simulate = true;
            $this->_out->logWarning("only SIMULATION mode");
        } else {
            if ($this->_options['update'] === false || (int)$this->_options['update'] === 0) {
                $this->_out->logNotice("skipped, not requested and not needed");
                return;
            }
            $simulate = false;
        }

        /** @var $compare Compare_Interface */
        $compare = $drivers['compare'];
        /** @var $local Storage_Interface */
        $local = $drivers['local'];

        if (!$compare->initChangesOn("remote")) {
            // TODO not sure, but maybe we will need it
        }

        $job = $this->_out->jobStart("updating remote storage");
        $this->_out->jobSetProgressStep($job, 1000);
        foreach ($compare as $task) {
            $repeat = 3;
            do {
                $msg = "";
                try {
                    $path = $this->_getPathWithBasedir($task->path, self::ADD_BASE_DIR);

                    switch ($task->action) {
                        case Compare_Interface::CMD_MKDIR:
                            $msg = "mkdir " . $path . " into s3 bucket";
                            $this->_out->logDebug($msg);
                            if (!$simulate) {
                                // create folders
                                $this->_s3->create_object(
                                    $this->getBucket(), $path,
                                    array(
                                        'body' => '',
                                        'storage' => $this->_defaultRedundancyStorage
                                    )
                                );
                            }
                            break;
                        case Compare_Interface::CMD_PUT:
                            $msg = "put " . $path . " into s3 bucket";
                            $this->_out->logDebug($msg);
                            $uploadPath = $local->getBaseDir() . $task->path;

                            //fix for windows encoding issue
                            $uploadPath = $local->convertEncodingPath($uploadPath);

                            if (!file_exists($uploadPath)) {
                                $this->_out->logError("file $uploadPath does not exists anymore locally");
                                continue;
                            }
                            if (!$simulate) {
                                //empty directory
                                if (ord(substr($path, -1)) === 47) {
                                    //for empty folders we need little different options
                                    $this->_out->logWarning("TODO putting empty folder $path ... is it possible ?");
                                    $this->_s3->create_object(
                                        $this->getBucket(), $path,
                                        array(
                                            'body' => '',
                                            'storage' => $this->_defaultRedundancyStorage
                                        )
                                    );
                                } else {
                                    $options = array('fileUpload' => $uploadPath, 'storage' => $this->_defaultRedundancyStorage);
                                    // TODO it should be possible to speedup upload of small upload but using S3 batch
                                    if ($this->_options['multipart']['big-files']) {
                                        // multipart upload for big files
                                        if ($this->_options['multipart']['part-size']) {
                                            $options['partSize'] = $this->_options['multipart']['part-size'];
                                        }
                                        $this->_s3->create_mpu_object($this->getBucket(), $path, $options);
                                    } else {
                                        // normal upload
                                        $this->_s3->create_object($this->getBucket(), $path, $options);
                                    }
                                }
                            }
                            break;
                        case Compare_Interface::CMD_DELETE:
                            $msg = "deleting " . $path . " from s3 bucket";
                            $this->_out->logDebug($msg);
                            if (!$simulate) {
                                $this->_s3->delete_object(
                                    $this->getBucket(), $path
                                );
                            }
                            break;
                        case Compare_Interface::CMD_TS:
                            // storing this information as metadata is too slow to be used
                            //                        $this->_out->logDebug("remember local timestamp for " . $path . " into s3 bucket");
                            //                        if (!$simulate) {
                            //                            $this->_s3->update_object(
                            //                                $this->getBucket(), $path,
                            //                                array(
                            //                                     'meta' => array('localts' => $task->ltime),
                            //                                )
                            //                            );
                            //                        }
                            break;
                        default:
                            $this->_out->logError("ignored command {$task->action}");

                    }
                    $repeat = 0;
                } catch (Exception $e) {
                    $repeat--;
                    if ($repeat) {
                        $this->_out->logError("need to repeat: $msg");
                    } else {
                        if ($msg) {
                            $this->_out->logError($msg);
                        }
                        throw new Exception($e->getMessage(), $e->getCode());
                    }
                }
            } while ($repeat);

            if (!$simulate) {
                $compare->remoteHasDone($task);
            }

            $this->_out->jobStep($job);
        }
        $this->_out->jobEnd($job, "remote storage updated");
    }

    protected function _getPathWithBasedir($path, $flag = self::REMOVE_BASE_DIR)
    {
        $baseDir = $this->getBaseDir();

        if (!empty ($baseDir)) {
            if (ord(substr($baseDir, -1)) !== 47) {
                $baseDir .= '/';
            }
            if ($flag == self::REMOVE_BASE_DIR) {
                $path = substr($path, strlen($baseDir));

            } elseif ($flag == self::ADD_BASE_DIR) {
                $path = $baseDir . $path;
            }
        }

        return $path;
    }

    static public function getConfigOptions($part = null)
    {
        $opt = array(
            CfgPart::DEFAULTS => array(
                'certificate_authority' => true,
                'defaultRedundancyStorage' => 'STORAGE_STANDARD',
                'refresh' => false,
                'update' => false,
                'compatibility-test' => false,
                'warn-versioning' => true,
                'multipart' => array(
                    'big-files' => true,
                    'part-size' => null,
                ),
            ),
            CfgPart::DESCRIPTIONS => array(
                'certificate_authority' => 'see https://forums.aws.amazon.com/ann.jspa?annID=1005',
                'bucket' => 'Amazon S3 bucket name',
                'defaultRedundancyStorage' => 'STORAGE_STANDARD or STORAGE_REDUCED',
                'basedir' => 'base directory in bucket to compare with local, make sure it doesn\'t start with slash (/)',
                'refresh' => 'read actual data from S3 and feed compare driver ? (yes/no/never)',
                'update' => <<<TXT
should upload/remove of data be executed ? (yes/no/simulate)
  yes:      will transfer data to S3
  no:       will not start update process
  simulate: will output progress, but will not really transfer data
TXT
            ,
                'compatibility-test' => <<<TXT
Find out if yur PC is compatible with Amazon PHP SDK, it will always stop the application if enabled.
TXT
            ,

                'key.access' => <<<TXT
S3 authentification key
Best practice is to place this option into separate INI file readable only by user executing backup.
TXT
            ,
                'key.secret' => <<<TXT
S3 authentification key
Best practice is to place this option into separate INI file readable only by user executing backup.
TXT
            ,
                'multipart.big-files' => <<<TXT
TRUE/FALSE enable multipart upload of big files. It speeds up upload.
TXT
            ,
                'multipart.part-size' => <<<TXT
The size of an individual part. The size may not be smaller than 5 MB or larger than 500 MB. The default value is 50 MB.
TXT
            ,
                'warn-versioning' => <<<TXT
If S3 bucket versioning is disabled you will not be able to restore older versions of files.
FALSE will suppress warnings in case that it is disabled.
TXT


            ),
            CfgPart::REQUIRED => array('bucket' => true, 'key.access' => true, 'key.secret' => true)
        );

        if (is_null($part)) {
            return $opt;
        } else {
            return array_key_exists($part, $opt) ? $opt[$part] : array();
        }
    }

    public function getMd5($path)
    {
        $v = null;
        $retries = 3;
        do {
            try {
                $v = $this->_s3->get_object_headers($this->getBucket(), $path);
                $retries = 0;
            } catch (Exception $e) {
                $this->_out->logWarning("retry S3::getMd5() for $path");
                usleep(200);
                $retries--;
            }
        } while ($retries !== 0);

        if ($v === null || !array_key_exists('etag', $v->header)) {
            return false;
        }
        $md5 = str_replace('"', '', (string)$v->header['etag']);
        return $md5;
    }

    public function convertEncodingPath($path)
    {
        return $path;
    }

    /*
     * We do not suppose to enable the functionality
     * 
     * public function changeRedundancy()
    {
        if (isset($this->_options['redundancy']['change']) 
                && $this->_options['redundancy']['change']) {
            $type = AmazonS3::STORAGE_STANDARD;
            $str = "AmazonS3::STORAGE_STANDARD";
            if (isset($this->_options['redundancy']['type'])) {
                $type = constant($this->_options['redundancy']['type']);
                $str = $this->_options['redundancy']['type'];
            }
            $bsDir = $this->getBaseDir();
            if (!empty($this->_options['redundancy']['baseDir'])) {
                if (substr($this->_options['redundancy']['baseDir'], -1) != '/') {
                    $this->_options['redundancy']['baseDir'] .= "/";
                }
                $this->setBasedir($this->_options['redundancy']['baseDir']);
            }
            
           $this->_out->logNotice("starting to change redundancy storage to {$str}
               for bucket: '{$this->getBucket()}'"); 
               
           $this->_list(array($this, "_changeRedundancy"), array('type' => $type));
           $this->_out->logNotice("Redundancy storage changed to $str");
           //back value of base dir
           $this->setBasedir($bsDir);
        } else {
            $this->_out->logNotice("skipped, redundancy level change. Not requested");
        }
    }
    
    private function _changeRedundancy($v, $params)
    {
        $filename = (string)$v->Key;
        $this->_s3->change_storage_redundancy(
                $this->getBucket(), 
                $filename, 
                $params['type']);
        
        $this->_out->logNotice("redundancy storage changed for: $filename");
    }*/

    public function setBasedir($value)
    {
        $this->_baseDir = $value;
    }

    function refreshLocal($myrole, $drivers)
    {
        throw new Exception("Currently S3 driver can't be used as local driver. Ask for this on https://github.com/k2s/xtbackup.");
    }

    function updateLocal($myrole, $drivers)
    {
        throw new Exception("Currently S3 driver can't be used as local driver. Ask for this on https://github.com/k2s/xtbackup.");
    }
}
