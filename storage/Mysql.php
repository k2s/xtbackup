<?php
class Storage_Mysql extends Storage_Filesystem
{
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

        // mysql options

    }

    static public function getConfigOptions($part=null)
    {
        $opt = array(
            CfgPart::DEFAULTS=>array(
                'host'=>'localhost',
                'port'=>'3306',
            ),
            CfgPart::DESCRIPTIONS=>array(
                'host'=>'???',
                'port'=>'???',
            ),
            CfgPart::REQUIRED=>array('host', 'port')
        );

        // merge with Storage_Filesystem options
        $opt = Core_Engine::array_merge_recursive_distinct(parent::getConfigOptions(CfgPart::DEFAULTS), $opt);

        if (is_null($part)) {
            return $opt;
        } else {
            return array_key_exists($part, $opt) ? $opt[$part] : array();
        }
    }
}