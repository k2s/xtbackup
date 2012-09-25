<?php
class CfgPart
{
    /*** List of information returned from getConfigOptions() methods ***/
    const DEFAULTS = 1;
    const DESCRIPTIONS = 2;
    const REQUIRED = 3;
    const HINTS = 4;
    /**
     * Returns version of driver/engine.
     * Optional: engine version is used if not provided
     */
    const VERSION = 5;
    /**
     * Options listed here will be stored in compare driver.
     * They invalidate any cached data if changed in next run.
     */
    const MONITOR = 6;
    /**
     * Options listed here will be repeated in end of INI files.
     * They are suggested to be changed to manipulated program execution.
     */
    const SUGGESTED = 7;

    /*** List of hint categories ***/
    const HINT_TYPE = "type";

    /*** List of data types used in INI configuration ***/
    const TYPE_NUMBER = 1;
    const TYPE_STRING = 2;
    const TYPE_BOOL = 3;
    const TYPE_ARRAY = 4;
    const TYPE_UNKNOWN = 0;
    const TYPE_PATH = 5; // Core_Engine::array_merge_defaults() will expand ~/ to getenv("HOME")/
}
