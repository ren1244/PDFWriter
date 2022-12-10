<?php
spl_autoload_register(function ($class_name) {
    $arr = explode('\\', $class_name);
    if(count($arr)>2 && $arr[0] === 'ren1244' && $arr[1] === 'PDFWriter') {
        $arr[0] = __DIR__;
        $arr[1] = '../src';
        require(implode('/',$arr).'.php');
    } else {
        echo 'cannot autoload class: '.$class_name;
        die();
    }
});

use ren1244\PDFWriter\FontLib\FontLoader;

$count = count($argv);
if ($count === 2) {
    $fname = getcwd() . '/' . parse_url($argv[1])['path'];
    if (!file_exists($fname) || is_dir($fname)) {
        exit("file $fnamenot not exists");
    }

    $ttf = new FontLoader;
    $ttf->loadFile($fname);
} elseif ($count === 3) {
    $fname = getcwd() . '/' . parse_url($argv[1])['path'];
    $outname = $argv[2];
    if (!file_exists($fname) || is_dir($fname)) {
        exit("file $fnamenot not exists");
    }
    $ttf = new FontLoader;
    $ttf->loadFile($fname, $outname);
}
