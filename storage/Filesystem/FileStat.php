<?php
/**
 * Fixes problems with big files in 32bit PHP
 *
 * based on Jamie Curnow's http://www.phpclasses.org/browse/file/32900.html
 */
class Storage_Filesystem_FileStat
{
	const OPSYS_LINUX         = 1;
	const OPSYS_BSD           = 2;
	const OPSYS_WIN           = 3;

	/**
	 * What is this operating system?
	 *
	 * @var     int
	 * @access  protected
	 *
	 **/
	protected $_opsys = self::OPSYS_LINUX;

	/**
	 * Should we use Windows COM objects?
	 *
	 * @var     bool
	 * @access  protected
	 *
	 **/
	protected $_use_win_com = false;

	/**
	 * Are we using a 32 bit operating system/PHP binary?
	 *
	 * @var     bool
	 * @access  protected
	 *
	 **/
	protected $_is_32_bit = true;
	/**
	 * 
	 * @var string 
	 */
	protected $_windowsEncoding = "CP1251";
	/**
	 * Constructor
	 *
	 * @return void
	 */
	public function __construct($useWinCom=false) {
		$this->_determineOpSys();
		$this->_is_32_bit = (PHP_INT_MAX > 2147483647 ? false : true);
		$this->_use_win_com = (bool) $useWinCom;
	}
        
	public function setWindowsEncoding($encoding)
	{
		$this->_windowsEncoding = $encoding;
	}

	/**
	 * Determine the operating system we're running on
	 *
	 * @access protected
	 * @return int
	 */
	protected function _determineOpSys() {
		$name = strtolower(php_uname('s'));
		if (strpos($name, 'win') !== false) {
			$this->_opsys = self::OPSYS_WIN;
		} else if (strpos($name, 'bsd') !== false) {
			$this->_opsys = self::OPSYS_BSD;
		} else if (strpos($name, 'linux') !== false) {
			$this->_opsys = self::OPSYS_LINUX;
		}
	}

	public function getStat($fullPath)
	{
            
		if (!$this->_is_32_bit) {
			$a = stat($fullPath);
		}
		// 32bit fix
		if ($this->_opsys == self::OPSYS_WIN && $this->_use_win_com) {
			$stat = $this->_getStatCOM($fullPath);
		} else {
			if (false === ($stat = @stat($fullPath)) ) {
				if ($this->_opsys == self::OPSYS_WIN) {
					$stat = $this->_getStatCOM($fullPath);
				} else {
                                    
					return $this->getStatLinux($fullPath);
				}
			}
		}
                //fix for size difference in empty folders
                if (self::isEmptyDir($fullPath)) {
                    $stat['size'] = 0;
                }
		return $stat;
	}
        
        public static function isEmptyDir($fullPath)
        {
            if (is_dir($fullPath)) {
                
              if (($files = @scandir($fullPath)) && count($files) <= 2) {
                  
                return true;
              }
           }
             return false; 
        }

        protected function getStatLinux($fullPath)
	{
		// $fullPath = exec ('readlink -f '. escapeshellarg ($file));
		$fullPath = escapeshellarg($fullPath);
		if (self::OPSYS_BSD==$this->_opsys) {
			// TODO not working
			$cmd = 'stat -f %z '.$fullPath;
			throw new Exception("BSD not supported.");
		} else {
			$cmd = 'stat -L -t --format=%s,%x,%y,%z '.$fullPath;
		}

		$out=array();
		$ret=false;
		$a = exec($cmd, $out, $ret);
		if ($ret!=0) {
			// problem
			return false;
		}

		// parse returned values
		$a = explode(",", $a);
		return array(
			'size'=>$a[0],
			'atime'=>strtotime($a[1]),
			'mtime'=>strtotime($a[2]),
			'ctime'=>strtotime($a[3]),
		);
	}

	protected function _getStatCOM($fullPath)
	{
            $fullPath = mb_convert_encoding($fullPath, $this->_windowsEncoding, "auto");
            
            $fsobj = new COM("Scripting.FileSystemObject");
            
            //COM returns size of whole folder. In situation when folder contains
            //only files excluded by filter this folder must be treated as empty one
            //with size 0.
            $size = 0;
            try {
                $f = $fsobj->GetFile($fullPath);
                $size = $f->Size;
            } catch(Exception $e) {
                //empty folder support
                $f = $fsobj->GetFolder($fullPath);
            } catch (Exception $e)
            {
                
            }
            
            return array(
                'size'=>$size,
                'atime'=>strtotime($f->DateLastAccessed),
                'mtime'=>strtotime($f->DateLastModified),
                'ctime'=>strtotime($f->DateCreated),
            );
//		
	}
        
        public function defineMd5($path)
        {
            //hack for windows encoding issue
            if ($this->_opsys == self::OPSYS_WIN)
            {
               $path = mb_convert_encoding($path, $this->_windowsEncoding, "auto");
               $fsobj = new COM("Scripting.FileSystemObject");
               $f = false;
               try{
                   $fsobj->GetFolder($path);
                   $f = true;
               } catch (Exception $e) {
                   $f = false;
               }
               
               if ($f) {
                   return md5("");
               }
               //exit("converted ".$path);
            }
            
               return md5_file($path);
            
        }
}