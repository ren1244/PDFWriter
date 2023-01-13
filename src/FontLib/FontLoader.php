<?php

namespace ren1244\PDFWriter\FontLib;

use Exception;
use ren1244\PDFWriter\Config;
use ren1244\sfnt\Sfnt;
use ren1244\sfnt\TypeReader;

class FontLoader
{
    public function loadFile($filename, $outputNmae = false)
    {
        $data = file_get_contents($filename);
        $font = new Sfnt(new TypeReader($data));
        switch ($font->sfntVersion) {
            case 0x00010000:
                $jsonData = ['type' => 'TTF'];
                break;
            case 0x4F54544F:
                $jsonData = ['type' => 'OTF'];
                break;
            default:
                throw new Exception('Not TTF or OTF');
        }
        if ($outputNmae === false) {
            $nameTable = $font->table('name');
            $namesArray = $nameTable->getNames(3, 10);
            if($namesArray === null) {
                $namesArray = $nameTable->getNames(3, 1);
            }
            if($namesArray === null) {
                throw new Exception('(platformId, encodingId) = (3,1) or (3,1) not exists');
            }
            if(!isset($namesArray[6])) {
                throw new Exception('no postscript fontname');
            }
            $outputNmae = $namesArray[6];
        }
        $jsonData = json_encode($jsonData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents(Config::FONT_DIR . '/custom/' . $outputNmae . '.json', $jsonData);
        file_put_contents(Config::FONT_DIR . '/custom/' . $outputNmae . '.bin', $data);
    }
}
