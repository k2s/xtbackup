<?php
// make sure we will find include files
set_include_path(realpath(dirname(__FILE__)));
/**
 * gets files from s3 server
 * usage example: php -f xts3get.php access="******" secret="**********" bucket="xtartem" basedir="/home/mamay/Documents/test/xs3get" remoteBasedir="files"
 *
 * @package Xtbackup
 */
require "lib/AWSSDKforPHP/sdk.class.php";

/**
 * gets files from s3 server
 *
 * @package Xtbackup
 */
class Xts3get 
{
    protected $_options = array();
    
    protected $_s3;
    
    protected $_updatedCount = 0;
    
    public function __construct($argv) 
    {
        define('AWS_CERTIFICATE_AUTHORITY', true);
        $this->_prepareParams($argv);
        $this->_s3 = new AmazonS3($this->_options['access'], $this->_options['secret']);
        $this->_out(">>> Params initialised. Amazon s3 object created");
    }
    
    protected function _prepareParams($cliParams)
    {
        foreach ($cliParams as $param) {
            if (strpos($param, "=")) {
                $splited = explode("=", $param);
                $this->_options[trim($splited[0])] = trim($splited[1]);
            }
        }
    }
    
    protected function _out($v)
    {
        echo $v.PHP_EOL;
    }
    
    /**
     * 
     * @param CFSimpleXML $o object gotten from list response
     */
    protected function _accept($o)
    {
        $filename = $localFilename = $this->_getLocalFilename($o);
        if (!file_exists($filename)) {
            return true;
        } else {
            if (md5_file($filename) != trim((string)$o->ETag, '"')) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 
     * @param CFSimpleXML $o object gotten from list response
     * 
     * @return String
     */
    protected function _getLocalFilename(&$o)
    {
        $basedir = $this->_options['basedir'];
        return $basedir.substr((string)$o->Key, strlen(trim($this->_options['remoteBasedir'],"/")), strlen((string)$o->Key) - 1 );
    }
    
    /**
     * 
     * @param CFSimpleXML $o object gotten from list response
     */
    protected function _update($o) 
    {
        // prepare data for loop
        $bucket = $this->_options['bucket'];
        $localFilename = $this->_getLocalFilename($o);
        
        $response = $this->_s3->get_object(
                $bucket, 
                (string)$o->Key, 
                array(
                    'fileDownload' => $localFilename
                ));
        
        if ($response->isOK()) {
            $this->_out(">>>Updated: $localFilename");
            $this->_updatedCount++;
        }
    }
    


    public function updateFromRemote()
    {
        // prepare data for loop
        $bucket = $this->_options['bucket'];
        $remoteBasedir = $this->_options['remoteBasedir'];
        $marker = '';
        $itemCount = 0;
        $v = false;
        
        $firstBatch = true;
        $this->_out(">>> Starting update");
        do {
            //create list of objects on s3 server
            $list = $this->_s3->list_objects(
                $bucket,
                array(
                     'marker' => $marker,
                     'prefix' => $remoteBasedir,
                )
            );
            
            if (!is_object($list->body->Contents)) {
                $this->_out("S3 response problem, no content returned");
                exit;
            }
            
            $count = $list->body->Contents->count();
            if ($count === 0) {
                if ($firstBatch) {
                    break;
                } else {
                    $this->_out("S3 response problem, not all files returned");
                    exit;
                }
            }
            $itemCount += $count;
            
            $metaId = 0;
            foreach ($list->body->Contents as $v) {
                if ($this->_accept($v)) {
                    $this->_update($v);
                }
                
            }
            // move to next batch of files
            $marker = $v->Key;
            $firstBatch = false;
        } while((string) $list->body->IsTruncated == 'true');
        
        $this->_out("All listed files: $itemCount");
        $this->_out("All updated files: {$this->_updatedCount}");
    }
    
     
}

$xts3 = new Xts3get($argv);
$xts3->updateFromRemote();

