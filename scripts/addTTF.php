<?php
require(__dir__ .'/../vendor/autoload.php');

use ren1244\PDFWriter\FontLib\TrueTypeLoader;

if(count($argv)!==2) {
    exit();
}
$fname=getcwd().'/'.parse_url($argv[1])['path'];
if(!file_exists($fname) || is_dir($fname)) {
    exit("file $fnamenot not exists");
}

$ttf=new TrueTypeLoader;
$ttf->loadFile($fname);