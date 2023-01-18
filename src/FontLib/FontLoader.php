<?php

namespace ren1244\PDFWriter\FontLib;

use Exception;
use ren1244\PDFWriter\Config;
use ren1244\sfnt\Sfnt;
use ren1244\sfnt\TypeReader;

class FontLoader
{
    public static function loadFile($filename, $outputNmae = false)
    {
        $data = file_get_contents($filename);
        $font = new Sfnt(new TypeReader($data));

        // 設定檔案名稱
        if ($outputNmae === false) {
            $nameTable = $font->table('name');
            $namesArray = $nameTable->getNames(3, 10);
            if ($namesArray === null) {
                $namesArray = $nameTable->getNames(3, 1);
            }
            if ($namesArray === null) {
                throw new Exception('(platformId, encodingId) = (3,1) or (3,1) not exists');
            }
            if (!isset($namesArray[6])) {
                throw new Exception('no postscript fontname');
            }
            $outputNmae = $namesArray[6];
        }
        $jsonFilename = Config::FONT_DIR . '/custom/' . $outputNmae . '.json';
        $binFilename = Config::FONT_DIR . '/custom/' . $outputNmae . '.bin';

        //輸出 json
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
        $jsonData = json_encode($jsonData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($jsonFilename, $jsonData);

        // 輸出 cache file
        $stream = fopen($binFilename, 'wb');
        fwrite($stream, pack('CN', 3, strlen($data)));
        fwrite($stream, $data);
        $data = $font->table('cmap')->createCache();
        fwrite($stream, pack('N', strlen($data)));
        fwrite($stream, $data);
        $data = $font->table('hmtx')->createCache();
        fwrite($stream, pack('N', strlen($data)));
        fwrite($stream, $data);
        fclose($stream);
    }

    public static function loadCache($fontFilename)
    {
        $cacheFile = fopen(Config::FONT_DIR . '/custom/' . $fontFilename . '.bin', 'rb');
        $count = ord(fread($cacheFile, 1));
        $cache = [];
        $tbnames = ['font', 'cmap', 'hmtx'];
        for ($i = 0; $i < $count; ++$i) {
            $len = unpack('N', fread($cacheFile, 4))[1];
            $cache[$tbnames[$i]] = fread($cacheFile, $len);
        }
        fclose($cacheFile);
        return $cache;
    }
}
