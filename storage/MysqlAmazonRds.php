<?php
require_once "lib/AWSSDKforPHP/sdk.class.php";

class Storage_MysqlAmazonRds extends Storage_Filesystem
{
    /**
     * @var Storage_Mysql
     */
    protected $_mysql;
    /**
     * @param Core_Engine  $engine
     * @param Output_Stack $output
     * @param array        $options
     *
     * @return \Storage_Filesystem
     */
    public function  __construct($identity, $engine, $output, $options)
    {
        // filesystem options
        parent::__construct($identity, $engine, $output, $options);

        // test options
        if (!array_key_exists('storage', $this->_options)) {
            $this->_out->stop("parameter 'storage' is required by driver '{$this->_identity}'");
        }

        if (!in_array($this->_options['ifexists'], array('exit', 'use'/*, 'recreate', 'keep'*/))) {
            // TODO recreate - drop and create, keep - use and don't drop in the end
            $this->_out->stop("invalid value of parameter 'ifexists' detected by driver '{$this->_identity}'");
        }

        if (!isset($this->_options['key'], $this->_options['key']['access'])) {
            throw new Core_StopException("You have to define Amazon RDS option key.access.", "MysqlAmazonRdsInit");
        }

        if (!isset($this->_options['key']['secret'])) {
            throw new Core_StopException("You have to define Amazon RDS option key.secret.", "MysqlAmazonRdsInit");
        }


        // check if we know the mysql storage
        $this->_mysql = $this->_engine->getStorage($this->_options['storage']);
        $this->setBaseDir($this->_mysql->getBaseDir());
    }

    static public function getConfigOptions($part=null)
    {
        // new options
        $opt = array(
            CfgPart::DEFAULTS=>array(
                //'storage'=>'localhost',
                'tempname'=>false,
                'certificate_authority' => true,
                'ifexists' => 'exit',
                'dbinstance'=>false,
                'dbinstanceclass' => false,
                'droptemp' => true,
            ),
            CfgPart::DESCRIPTIONS=>array(
                'storage'=>'configuration of mysql storage driver',
                'dbinstance' => 'DBInstance name in given region you want to backup',
                'tempname'=>'if provided this name will be used to create backup instance from snapshot',
                'ifexists' => 'how to handle situation the temporary backup instance already exists (exit,use)', /*recreate, keep*/
                'droptemp' => "created temporary RDS instance will be dropped after backup process finishes",
                'dbinstanceclass' => 'set instance class of temporary backup instance, same as original if not set',
                'region' => 'see http://docs.amazonwebservices.com/AWSSDKforPHP/latest/#m=AmazonRDS/set_region',
                'certificate_authority' => 'see https://forums.aws.amazon.com/ann.jspa?annID=1005',
                'key.access' => <<<TXT
Amazon RDS authentification key
Best practice is to place this option into separate INI file readable only by user executing backup.
TXT
                ,
                'key.secret' => <<<TXT
Amazon RDS authentification key
Best practice is to place this option into separate INI file readable only by user executing backup.
TXT
            ),
            CfgPart::REQUIRED=>array('storage', 'key.access', 'key.secret')
        );

        // add old options from Storage_Filesystem
        //Core_Engine::array_merge_configOptions(parent::getConfigOptions(), $opt);
/*        $optOld = parent::getConfigOptions();
        foreach ($opt as $k=>&$o) {
            if (array_key_exists($k, $optOld)) {
                $o = Core_Engine::array_merge_recursive_distinct($optOld[$k], $o);
            }
        }
        foreach ($optOld as $k=>$o) {
            if (!array_key_exists($k, $opt)) {
                $opt[$k] = $o;
            }
        }*/

        if (is_null($part)) {
            return $opt;
        } else {
            return array_key_exists($part, $opt) ? $opt[$part] : array();
        }
    }

    public function init($myRole, $drivers)
    {
        //parent::init($myRole, $drivers);
        $this->_out->logNotice(">>>init ".get_class($this)." driver as $myRole");

        // Amazon library SSL Connection Issues
        if (!defined('AWS_CERTIFICATE_AUTHORITY')) {
            define('AWS_CERTIFICATE_AUTHORITY', $this->_options['certificate_authority']);
        } else {
            $this->_out->logNotice("option 'certificate_authority' was already set, it can't be changed");
        }

        // receive information about the RDS instance
        $rds = new AmazonRDS(
            array(
                 'key'=>$this->_options['key']['access'],
                 'secret'=>$this->_options['key']['secret']
            )
        );
        if ($this->_options['region']) {
            $r = new ReflectionObject($rds);
            $rds->set_region($r->getConstant($this->_options['region']));
        }
        if ($this->_options['dbinstance']) {
            $response = $rds->describe_db_instances(
                array('DBInstanceIdentifier'=>$this->_options['dbinstance'])
            );
            if (!$response->isOK()) {
                throw new Core_StopException("Not possible to get information about RDS instance '".$this->_options['dbinstance']."'.", "MysqlAmazonRdsInit");
            }
            $instance = $response->body->DescribeDBInstancesResult->DBInstances[0]->DBInstance;
        } else {
            throw new Core_StopException("You have to provide parameter 'dbinstance', finding server based on server name/IP is not supported at this moment.", "MysqlAmazonRdsInit");
            $response = $rds->describe_db_instances();
            if (!$response->isOK()) {
                throw new Core_StopException("Not possible to get information about RDS instances.", "MysqlAmazonRdsInit");
            }
            // find instance name with mysql server configured in mysql storage
            foreach ($response->body->DescribeDBInstancesResult->DBInstances->children() as $instance) {
                if ($instance->Endpoint->Address=="") {
                    die;
                }
            }
        }

        //DBInstanceStatus
        $backupRetentionPeriod = $this->_fixGet($instance, 'BackupRetentionPeriod')*1;
        if (! $backupRetentionPeriod>0) {
            throw new Core_StopException("You need to set BackupRetentionPeriod>0 on RDS instance. Otherwise use the MySql and not the MysqlAmazonRds storage class.", "MysqlAmazonRdsInit");
        }

        $engine = $this->_fixGet($instance, 'Engine');
        if ($engine!=="mysql") {
            throw new Core_StopException("RDS instances has to use MySql, the current engine is '$engine'.", "MysqlAmazonRdsInit");
        }


        if ($this->_options['tempname']) {
            $tempName = $this->_options['tempname'];
        } else {
            $tempName = $this->_fixGet($instance, 'DBInstanceIdentifier')."-BAK";
        }

        $response = $rds->describe_db_instances(
            array('DBInstanceIdentifier'=>$tempName)
        );
        $bakExists = $response->isOK();
        if ($bakExists && $this->_options['ifexists']=='exit') {
            throw new Core_StopException("There is already RDS instance named '$tempName', this name should be used for temporary DB instance.", "MysqlAmazonRdsInit");
        }

        if (!$bakExists) {
            // create temporary DB instance
            $opt = array(
                'UseLatestRestorableTime' => true,
                'AvailabilityZone' => $this->_fixGet($instance, 'AvailabilityZone'),
            );
            if (false!==$this->_options['dbinstanceclass']) {
                $opt['DBInstanceClass'] = $this->_options['dbinstanceclass'];
            }
            $rds->restore_db_instance_to_point_in_time($this->_options['dbinstance'], $tempName, $opt);
            $dbInstanceName = $this->_options['dbinstance'];
            $this->_out->logNotice("point in time restore of '$dbInstanceName' started and '$tempName' will be created");
        }

        // wait for readiness
        $job = $this->_out->jobStart("waiting for temporary RDS instance '$tempName' to become 'available'");
        do {
             $response = $rds->describe_db_instances(
                array('DBInstanceIdentifier'=>$tempName)
            );
            $tmpInstance = $response->body->DescribeDBInstancesResult->DBInstances[0]->DBInstance;
            $status = $this->_fixGet($tmpInstance, 'DBInstanceStatus');
            // TODO reverse this check, this way may loop forever if unpredicted status is returned
            if (in_array($status, array('available'))) {
                break;
            }
            if (in_array($status, array('failed', 'storage-full', 'incompatible-parameters', 'incompatible-restore'))) {
                throw new Core_StopException("RDS backup instance '$tempName' has stalled in status '$status'. Please fix the situation and restart backup.", "MysqlAmazonRdsInit");
            }
            sleep(3);
        } while (true);
        $this->_out->jobEnd($job, "ready");

        // configure and execute mysql backup
        $this->_mysql->setHost(
            $this->_fixGet($tmpInstance->Endpoint, 'Address'),
            $this->_fixGet($tmpInstance->Endpoint, 'Port')
        );
        $drivers['local'] = $this->_mysql;
        $this->_mysql->init($myRole, $drivers);

        if ($this->_options['droptemp']) {
            // drop temporary instance
            $job = $this->_out->jobStart("droping temporary RDS instance '$tempName'");
            $response = $rds->delete_db_instance(
                $tempName,
                array(
                    'SkipFinalSnapshot'=>true
                )
            );
            if (!$response->isOK()) {
                $this->_out->jobEnd($job, "failed");
            } else {
                $this->_out->jobEnd($job, "started, not waiting for finish");
            }
        }
    }

    protected function _fixGet($o, $key)
    {
        // fixes segmentation fault in PHP 5.4.7
        $o = $o->$key->to_array();
        return $o[0];
    }

    public function refreshLocal($myRole, $drivers)
    {
        if (method_exists($this->_mysql, "refreshLocal")) {
            $this->_mysql->refreshLocal($myRole, $drivers);
        }
    }

    public function refreshRemote($myRole, $drivers)
    {
        if (method_exists($this->_mysql, "refreshRemote")) {
            $this->_mysql->refreshRemote($myRole, $drivers);
        }
    }
    public function compare($myRole, $drivers)
    {
        if (method_exists($this->_mysql, "compare")) {
            $this->_mysql->compare($myRole, $drivers);
        }
    }

    public function updateRemote($myRole, $drivers)
    {
        if (method_exists($this->_mysql, "updateRemote")) {
            $this->_mysql->updateRemote($myRole, $drivers);
        }
    }

    public function shutdown($myRole, $drivers)
    {
        if (method_exists($this->_mysql, "shutdown")) {
            $this->_mysql->shutdown($myRole, $drivers);
        }
    }
}
