<?php
$action = $argv[1];
$objectName = $argv[2];

switch ($action) {
    case "test":
        // control value
        exit(123);
    case "data":
        // restrict what data we want to import
        if (substr($objectName, 0, 5)=="help_") {
            // we don't want to import tables with names beginning help_
            exit(0);
        }
        break;
}

// process rest
exit(1);