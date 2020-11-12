<?php
require(__dir__ .'/../vendor/autoload.php');

use ren1244\PDFWriter\FontLib\TrueTypeLoader;

$count=count($argv);
if($count===2) {
    $fname=getcwd().'/'.parse_url($argv[1])['path'];
    if(!file_exists($fname) || is_dir($fname)) {
        exit("file $fnamenot not exists");
    }

    $ttf=new TrueTypeLoader;
    $ttf->loadFile($fname);
} elseif($count===3) {
    $fname=getcwd().'/'.parse_url($argv[1])['path'];
    $outname=$argv[2];
    if(!file_exists($fname) || is_dir($fname)) {
        exit("file $fnamenot not exists");
    }
    $ttf=new TrueTypeLoader;
    $ttf->loadFile($fname, $outname);
}
