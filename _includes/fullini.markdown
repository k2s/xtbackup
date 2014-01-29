; xtbackup.php configuration file for version 0.1.UNKNOWN

;***************************************************************************************
; listed options are more often changed then other - see description later in this file

; engine.outputs[] = 
; compare.mysqlite.testing = 
; compare.mysqlite.rebuild = 
; compare.mysqlite.keep = 
;***************************
; core engine configuration

; Register directories with additional drivers.
; Structure of such directory has to follow xtbackup folder hierarchy.
; It is possible to use ENGINE_DIR constant in INI, which points to parent folder of core/Engine.php.
; 
; Example:
; engine.extensions[] = ENGINE_DIR "/examples/plugins"
;
; default:
;     engine.extensions[] =

; engine.extensions[] = 

; Reference output configuration key(s) which will be used to process output from engine.
; You may configure multiple output configurations to be used by engine.
; 
; Example: engine.outputs[] = mycli
;
; default:
;     engine.outputs[] = cli

; see suggested section for values

; Set process priority. Doesn't work on Windows.
; See PHP/proc_nice and `man nice` for more info.
;
; default:
;     engine.nice = 

; engine.nice = 

; Limit backup instances to run only once instance globaly (value 1) or per configuration file (value 2).
; 
; Example:
; engine.lock-type = 1
;
; default:
;     engine.lock-type = 2

; engine.lock-type = 

; What should additional instances (see lock-type) do:
; 0 - application wait until previous backup ends
; greater then 0 - how many seconds should current process wait for previous backup to end, then exit application
; false - don't wait, exit application
; 
; Example:
; engine.lock-wait = 0
;
; default:
;     engine.lock-wait = 0

; engine.lock-wait = 

;*************************
; definition of filter(s)

; Driver class
;
filter.myregexp.class = Filter_RegExp

;***********************************
; configuration of output driver(s)

; Driver class
;
output.myblackhole.class = Output_Blackhole

; Driver class
;
output.mycli.class = Output_Cli

; What level of information do you want to see in output.
; Possible values: CRITICAL, ERROR, WARNING, NOTICE, DEBUG',
;
; default:
;     output.mycli.verbosity = notice

; output.mycli.verbosity = 

; Should progress be visually presented in output.
;
; default:
;     output.mycli.progress = 1

; output.mycli.progress = 

;*******************************
; definition of storage drivers

; Driver class
;
storage.mydummy.class = Storage_Dummy

; Driver class
;
storage.myfilesystem.class = Storage_Filesystem

; read actual data about file system and feed compare driver ?
;
; default:
;     storage.myfilesystem.refresh = 

; storage.myfilesystem.refresh = 

; default:
;     storage.myfilesystem.windows = utf8

; storage.myfilesystem.windows = 

; ....
;
; storage.myfilesystem.windows.encoding = 

; Driver class
;
storage.mymysqlamazonrds.class = Storage_MysqlAmazonRds

; if provided this name will be used to create backup instance from snapshot
;
; default:
;     storage.mymysqlamazonrds.tempname = 

; storage.mymysqlamazonrds.tempname = 

; see https://forums.aws.amazon.com/ann.jspa?annID=1005
;
; default:
;     storage.mymysqlamazonrds.certificate_authority = 1

; storage.mymysqlamazonrds.certificate_authority = 

; how to handle situation the temporary backup instance already exists (exit,use)
;
; default:
;     storage.mymysqlamazonrds.ifexists = exit

; storage.mymysqlamazonrds.ifexists = 

; DBInstance name in given region you want to backup
;
; default:
;     storage.mymysqlamazonrds.dbinstance = 

; storage.mymysqlamazonrds.dbinstance = 

; set instance class of temporary backup instance, same as original if not set
;
; default:
;     storage.mymysqlamazonrds.dbinstanceclass = 

; storage.mymysqlamazonrds.dbinstanceclass = 

; created temporary RDS instance will be dropped after backup process finishes
;
; default:
;     storage.mymysqlamazonrds.droptemp = 1

; storage.mymysqlamazonrds.droptemp = 

; see http://docs.amazonwebservices.com/AWSSDKforPHP/latest/#m=AmazonRDS/set_region
;
; storage.mymysqlamazonrds.region = 

; Amazon RDS authentification key
; Best practice is to place this option into separate INI file readable only by user executing backup.
;
; storage.mymysqlamazonrds.key.access = 

; Amazon RDS authentification key
; Best practice is to place this option into separate INI file readable only by user executing backup.
;
; storage.mymysqlamazonrds.key.secret = 

; Driver class
;
storage.mys3.class = Storage_S3

; see https://forums.aws.amazon.com/ann.jspa?annID=1005
;
; default:
;     storage.mys3.certificate_authority = 1

; storage.mys3.certificate_authority = 

; STORAGE_STANDARD or STORAGE_REDUCED
;
; default:
;     storage.mys3.defaultRedundancyStorage = STORAGE_STANDARD

; storage.mys3.defaultRedundancyStorage = 

; read actual data from S3 and feed compare driver ? (yes/no/never)
;
; default:
;     storage.mys3.refresh = 

; storage.mys3.refresh = 

; should upload/remove of data be executed ? (yes/no/simulate)
;   yes:      will transfer data to S3
;   no:       will not start update process
;   simulate: will output progress, but will not really transfer data
;
; default:
;     storage.mys3.update = 

; storage.mys3.update = 

; Find out if yur PC is compatible with Amazon PHP SDK, it will always stop the application if enabled.
;
; default:
;     storage.mys3.compatibility-test = 

; storage.mys3.compatibility-test = 

; If S3 bucket versioning is disabled you will not be able to restore older versions of files.
; FALSE will suppress warnings in case that it is disabled.
;
; default:
;     storage.mys3.warn-versioning = 1

; storage.mys3.warn-versioning = 

; default:
;     storage.mys3.multipart = 1
;     storage.mys3.multipart = 

; storage.mys3.multipart = 

; REQUIRED
; S3 authentification key
; Best practice is to place this option into separate INI file readable only by user executing backup.
;
; storage.mys3.key.access = <enter your value and uncomment>

; REQUIRED
; S3 authentification key
; Best practice is to place this option into separate INI file readable only by user executing backup.
;
; storage.mys3.key.secret = <enter your value and uncomment>

; TRUE/FALSE enable multipart upload of big files. It speeds up upload.
;
; storage.mys3.multipart.big-files = 

; The size of an individual part. The size may not be smaller than 5 MB or larger than 500 MB. The default value is 50 MB.
;
; storage.mys3.multipart.part-size = 

; Driver class
;
storage.mymysql.class = Storage_Mysql

; read actual data about file system and feed compare driver ?
;
; default:
;     storage.mymysql.refresh = 

; storage.mymysql.refresh = 

; default:
;     storage.mymysql.windows = utf8

; storage.mymysql.windows = 

; mysql server host name
;
; default:
;     storage.mymysql.host = localhost

; storage.mymysql.host = 

; mysql server port number
;
; default:
;     storage.mymysql.port = 3306

; storage.mymysql.port = 

; mysql user name
;
; default:
;     storage.mymysql.user = root

; storage.mymysql.user = 

; mysql user password
;
; default:
;     storage.mymysql.password = 

; storage.mymysql.password = 

; compress data files on the fly
;
; default:
;     storage.mymysql.compressdata = 

; storage.mymysql.compressdata = 

; default:
;     storage.mymysql.addtobasedir = 

; storage.mymysql.addtobasedir = 

; default:
;     storage.mymysql.rotate = 0
;     storage.mymysql.rotate = 0
;     storage.mymysql.rotate = 0

; storage.mymysql.rotate = 

; specify external filter application like 'php -f filter.php -- '
;
; default:
;     storage.mymysql.filter-ext = 

; storage.mymysql.filter-ext = 

; for how many days should backups be kept
;
; storage.mymysql.rotate.days = 

; for how many weeks should backups be kept
;
; storage.mymysql.rotate.weeks = 

; for how many months should backups be kept
;
; storage.mymysql.rotate.months = 

;*********************************
; definition of compare driver(s)

; Driver class
;
compare.mysqlite.class = Compare_Sqlite

; stored compare data will not be changed when finished
;
; default:
;     compare.mysqlite.testing = false

; see suggested section for values

; reset all data in table
;
; default:
;     compare.mysqlite.rebuild = false

; see suggested section for values

; don't drop testing table from DB if testing=true
;
; default:
;     compare.mysqlite.keep = false

; see suggested section for values

; should compare be executed ?
;
; default:
;     compare.mysqlite.compare = true

; compare.mysqlite.compare = 

; REQUIRED
; path and file name of sqlite database file to use
;
; compare.mysqlite.file = <enter your value and uncomment>
