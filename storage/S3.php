<?php
require "lib/AWSSDKforPHP/sdk.class.php";

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
     *
     * @var Core_Engine
     */
    protected $_engine;
    /**
     * @var Output_Stack
     */
    protected $_out;
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

    /**
     * @param Core_Engine  $engine
     * @param Output_Stack $output
     * @param array        $options
     *
     * @return \Storage_S3
     */
    public function  __construct($engine, $output, $options)
    {
        // merge options with default options
        $options = $engine::array_merge_recursive_distinct(self::getConfigOptions(CfgPart::DEFAULTS), $options);

        // TODO see Compare_Sqlite constructor

        /*if ($this->_params['upload']['part-size'] < 5  ||  $this->_params['upload']['part-size'] > 5120) {
            XtS3Backup_Core::suicide(
                "Amazon S3 part size limit from 5MiB to 5GiB. "
                ."remote.s3.upload.part-size key defined in MiB and expected to be between 5 and 5120"
            );
        }        */

        $this->_out = $output;
        $this->_options = $options;
        $this->_engine = $engine;
    }

    public function init($myrole, $drivers)
    {
        $this->_out->logNotice(">>>init S3 driver as $myrole");
        $this->_asRemote = $myrole == Core_Engine::ROLE_REMOTE;

        // Amazon library SSL Connection Issues
        define('AWS_CERTIFICATE_AUTHORITY', $this->_options['certificate_authority']);

        if ($this->_options['compatibilityTest']) {
            // see lib/AWSSDKforPHP/_compatibility_test
            $this->_out->jobStart("executing Amazon SDK compatibility test");
            include "lib/AWSSDKforPHP/_compatibility_test/sdk_compatibility_test_cli.php";
            $this->_out->stop("-- re-run without --");
        }

        $job = $this->_out->jobStart("handshaking with Amazon S3");
        // TODO we need better AmazonS3 error handling
        $this->_s3 = new AmazonS3($this->_options['key']['access'], $this->_options['key']['secret']);
        if (false == $this->_s3->if_bucket_exists($this->getBucket())) {
            $this->_out->jobEnd($job, "failed");
            $this->_out->stop("S3 bucket not found: '{$this->getBucket()}'");
        }
        $this->_out->jobEnd($job, "authorized");

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

        $job = $this->_out->jobStart("downloading info about files stored in Amazon S3");
        $this->_out->jobSetProgressStep($job, 100);

        // prepare data for loop
        $bucket = $this->getBucket();
        $baseDir = $this->getBaseDir();
        $marker = '';
        $itemCount = 0;
        $v = false;

        // let compare driver know that we are starting
        $compare->updateFromRemoteStart();

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
            $itemCount += $count;

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
                /** @var $v CFResponse */
                // save object
//                $meta = $response[$metaId++];
                $fsObject = $this->_createFsObject($v, null);
                $fsObject->path = $this->_getPathWithBasedir($fsObject->path);
                $compare->updateFromRemote($fsObject);

                // TODO update progress, needs better clarificatio
                $this->_out->jobStep($job);
            }
            $this->_out->jobEnd($jobFiles, "updated info about one batch of files");

            // move to next batch of files
            $marker = $v->Key;
            $firstBatch = false;
        } while ((string) $list->body->IsTruncated == 'true');

        // let compare driver know that we are done
        $compare->updateFromRemoteEnd();

        $this->_out->jobEnd($job, "downloaded info about $itemCount files");
    }

    /**
     *
     * @param CFResponse $v
     * @param CFResponse $meta
     *
     * @return Core_FsObject
     */
    public function _createFsObject($v, $meta=null)
    {
        $path = (string) $v->Key;

        // $path ends with '/' or $path ends with '_$folder$'
        $isDir = ('/' == substr($path, -1)) || ('_$folder$' == substr($path, 9));
        $obj = new Core_FsObject($path, $isDir, (float)$v->Size, (string) $v->LastModified, str_replace('"', '', (string)$v->ETag));
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
            try {
                $path = $this->_getPathWithBasedir($task->path, self::ADD_BASE_DIR);

                switch ($task->action)
                {
                    case Compare_Interface::CMD_MKDIR:
                        $this->_out->logDebug("mkdir " . $path . " in s3 bucket");
                        if (!$simulate) {
                            // create folders
                            $this->_s3->create_object(
                                $this->getBucket(), $path,
                                array(
                                     'body' => '',
                                )
                            );
                        }
                        break;
                    case Compare_Interface::CMD_PUT:
                        $this->_out->logDebug("put " . $path . " in s3 bucket");
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
                                    )
                                );
                            } else {
                                $this->_s3->create_object(
                                    $this->getBucket(), $path,
                                    array(
                                         'fileUpload' => $uploadPath,
                                         'meta' => array('localts' => $task->ltime),
                                    )
                                );
                            }
                        }
                        break;
                    case Compare_Interface::CMD_DELETE:
                        $this->_out->logDebug("deleting " . $path . " from s3 bucket");
                        if (!$simulate) {
                            $this->_s3->delete_object(
                                $this->getBucket(), $path
                            );
                        }
                        break;
                    case Compare_Interface::CMD_TS:
                        // storing this information as metadata is too slow to be used
//                        $this->_out->logDebug("remember local timestamp for " . $path . " in s3 bucket");
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
            } catch (Exception $e) {
                throw new Exception($e->getMessage(), $e->getCode());
            }

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
                //'bucket'=>,
                'refresh' => false,
                'update' => false,
                'compatibilityTest' => false,
                //'key.access'=>,
                //'key.secret'=>,
            ),
            CfgPart::DESCRIPTIONS => array(
                'certificate_authority' => 'see https://forums.aws.amazon.com/ann.jspa?annID=1005',
                'bucket' => 'Amazon S3 bucket name',
                'basedir' => 'base directory in bucket to compare with local',
                'refresh' => 'read actual data from S3 and feed compare driver ? (yes/no/never)',
                'update' => <<<TXT
should upload/remove of data be executed ? (yes/no/simulate)
  yes:      will transfer data to S3
  no:       will not start update process
  simulate: will output progress, but will not really transfer data
TXT
            ,
                'compatibilityTest' => 'find out if yur PC is compatible with Amazon PHP SDK, it will always stop the application if enabled',
                'key.access' => 'S3 authentification key',
                'key.secret' => 'S3 authentification key',
            ),
            CfgPart::REQUIRED => array('bucket', 'key.access', 'key.secret')
        );

        if (is_null($part)) {
            return $opt;
        } else {
            return $opt[$part];
        }
    }

    public function getMd5($path)
    {
        $v = $this->_s3->get_object_headers($this->getBucket(), $path);
        $md5 = str_replace('"', '', (string)$v->header['etag']);
        return $md5;
    }

    public function convertEncodingPath($path)
    {
        return $path;
    }
/*
    function refreshLocal($myrole, $drivers)
    {
        throw new Exception("refreshLocal not supported in S3 driver");
    }
*/
}
