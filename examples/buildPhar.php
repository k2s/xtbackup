<?php
echo <<<CLI
/**
 * Will create PHAR file
 *
 * Your php.ini has to contain phar.readonly=0 to be able to produce PHAR files.
 */
CLI;

// find the root path of xtbackup project
$rootPath = realpath(dirname(__FILE__) . '/..');

// remove existing phar file (would not needed if excluded in RecursiveDirectoryIterator
$fn = $rootPath . '/xtbackup.phar';
@unlink($fn);

// define PHAR
$phar = new Phar($fn, 0, 'xtbackup.phar');
// this files will be included
$it = new RecursiveDirectoryIterator($rootPath);
$it->setFlags(RecursiveDirectoryIterator::SKIP_DOTS);
$phar->buildFromIterator(
    new RecursiveIteratorIterator($it),
    $rootPath
);
// let create the file
$phar->setStub($phar->createDefaultStub('xtbackup.php'));

// compress the file
$phar->compressFiles(Phar::BZ2);

echo "\n\n";
if (file_exists($fn)) {
    echo $fn.' created.';
} else {
    echo 'error.';
}