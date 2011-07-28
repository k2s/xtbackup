<?php
//namespace example;

class Storage_Simulation extends Storage_Filesystem
{
    public function init($myrole, $drivers)
    {
        $ret = parent::init($myrole, $drivers);

        if (true===$ret) {
            $this->_out->logNotice("generating simulated file system");
            // TODO prepare files needed for simulation if not already created
        }

        return $ret;
    }

    public function refreshLocal($myrole, $drivers)
    {
        if ($this->_asRemote) {
            // nothing to do if I am not remote driver
            return;
        }

        $this->_out->logNotice(">>>refresh from SIMULATED local file system");
        $this->_refreshSimulatedLocal($myrole, $drivers);
    }

    public function _refreshSimulatedLocal()
    {

    }
}