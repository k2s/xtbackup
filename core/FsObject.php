<?php

class Core_FsObject
{
    public $path;
    public $isDir;
    public $size;
    public $md5;
    
    public function  __construct($path, $isDir, $size, $lastModify, $md5=false)
    {
        // $path = str_replace("\\", "/", $path); think this has to be solved in storage driver already
        $this->isDir = 0;
        if ($isDir) {
            $path = rtrim($path, "/")."/";
            $this->isDir = 1;
        } elseif ('/'==substr($path, -1) && is_file($path)) {
            Core_Engine::$out->stop("FsObject: file '$path' can't end with /");
        }

        $this->path = (string) $path;
        // convert to UNIX timestamp
        $this->time = is_string($lastModify) ? strtotime($lastModify) : $lastModify;
        $this->size = (float) $size;
        if (false===$md5) {
            $this->md5 = false;
        } else {
            $this->md5 = (string) $md5;
        }
    }

    public static function factoryFromStat($path, $stat)
    {
        $isDir = false;
        if (isset($stat['mode'])) {
            $isDir = (($stat['mode'] & 0170000) == 040000);
        }
        
        $lastModify = date('c', max($stat['ctime'], $stat['mtime']));
        return new self($path, $isDir, $stat['size'], $lastModify, false);
    }
}
