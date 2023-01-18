<?php
$autoloadFileTry = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadFileTry)) {
    require($autoloadFileTry);
} else {
    $pos = strpos(__DIR__, 'vendor');
    if ($pos === false) {
        throw new Exception('autoload file not found');
    }
    $autoloadFileTry = substr(__DIR__, 0, $pos - 1) . '/vendor/autoload.php';
    if (file_exists($autoloadFileTry)) {
        require($autoloadFileTry);
    } else {
        throw new Exception('autoload file not found');
    }
}

use ren1244\PDFWriter\FontLib\FontLoader;

$count = count($argv);
if ($count === 2) {
    $fname = getcwd() . '/' . parse_url($argv[1])['path'];
    if (!file_exists($fname) || is_dir($fname)) {
        exit("file $fnamenot not exists");
    }
    FontLoader::loadFile($fname);
} elseif ($count === 3) {
    $fname = getcwd() . '/' . parse_url($argv[1])['path'];
    $outname = $argv[2];
    if (!file_exists($fname) || is_dir($fname)) {
        exit("file $fnamenot not exists");
    }
    FontLoader::loadFile($fname, $outname);
}
