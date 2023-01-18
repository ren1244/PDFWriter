<?php

namespace ren1244\PDFWriter\FontLib;

use Exception;
use ren1244\PDFWriter\Config;
use ren1244\sfnt\Sfnt;
use ren1244\sfnt\TypeReader;

class TrueType implements Font
{
    private $reader;
    private $font;

    private $fontname;
    private $unitsPerEm;
    private $cmap;
    private $hmtx;
    private $mtx;

    private $usedUnicode = [];
    private $fontProgramSize;
    private $subsetFont;

    /**
     * 依據 $fontFilename 初始化
     * 
     * @param string $fontFilename 字型辨識名稱
     */
    public function __construct($fontFilename, $ftJson)
    {
        // 讀取資料
        $cache = FontLoader::loadCache($fontFilename);
        // 讀入字型，並將 cache 寫入
        $this->reader = new TypeReader($cache['font']);
        $this->font = new Sfnt($this->reader);
        $this->cmap = $this->font->table('cmap');
        $this->cmap->loadCache($cache['cmap']);
        $this->hmtx = $this->font->table('hmtx');
        $this->hmtx->loadCache($cache['hmtx']);
        // 讀取 head
        $head = $this->font->table('head');
        $this->unitsPerEm = $head->unitsPerEm;
        // 從 name 讀取 fontname
        $nameTable = $this->font->table('name');
        if (
            (
                ($nameArray = $nameTable->getNames(3, 10)) === null &&
                ($nameArray = $nameTable->getNames(3, 1)) === null
            ) || !isset($nameArray[6])
        ) {
            throw new Exception('no postscript fontname');
        }
        $this->fontname = $nameArray[6];
        // 讀取 hhea, OS/2
        $hhea = $this->font->table('hhea');
        $os2 = $this->font->table('OS/2');
        $scale = 1000 / $head->unitsPerEm;

        $this->mtx = [
            'bbox' => [
                $head->xMin * $scale,
                $head->yMin * $scale,
                $head->xMax * $scale,
                $head->yMax * $scale
            ],
            'italicAngle' => $this->font->table('post')->italicAngle * $scale,
            'ascent' => $hhea->ascender * $scale,
            'descent' => $hhea->descender * $scale,
            'capHeight' => ($os2->sCapHeight ?? $hhea->ascender) * $scale,
            'typoAscender'  => $os2->sTypoAscender * $scale,
            'typoDescender' => $os2->sTypoDescender * $scale,
            'typoLineGap' => $os2->sTypoLineGap * $scale,
        ];
    }

    /**
     * 取得該 unicode 的寬度，如果超出字型範圍，回傳 false
     * （同時請記錄使用的 unicode，以供 subset 使用）
     * 
     * @param int $unicode unicode code point
     * @return int|false
     */
    public function getWidth($unicode)
    {
        if (($gid = $this->cmap->getGid($unicode)) === 0) {
            return false;
        }
        $this->usedUnicode[$unicode] = 1;
        return $this->hmtx->getWidth($gid);
    }

    /**
     * 回傳字型資訊，包含：
     * bbox: 陣列 [xMin, yMin, xMax, yMax]
     * italicAngle: 斜體角度
     * ascent: 基線以上高度
     * descent: 基線以下高度
     * capHeight: capHeight（稍微小於或等於 ascent）
     * typoAscent:  排版時的 Ascent
     * typoDescent: 排版時的 Descent
     * typoLineGap: 排版時的 LineGap
     * 
     * @return array 字型資訊
     */
    public function getMtx()
    {
        return $this->mtx;
    }

    //========以下為開始 write 後會被呼叫========

    /**
     * 取得類型，讓 FontController 類別作為產生 pdf 內容的依據
     * @return int 0 for Standard, 1 for TrueType, 2 for OpenType
     */
    public function getType()
    {
        return 1;
    }

    /**
     * 依據曾經 getWidth 的 unicodes，建立 subset
     * 
     * @return bool 如果回傳 false 則表示此字型沒有被使用到
     */
    public function subset()
    {
        if (empty($this->usedUnicode)) {
            return false;
        }
        ksort($this->usedUnicode, SORT_NUMERIC);
        $cmap = $this->cmap;
        $usedGID = [];
        $newGID = 0;
        foreach ($this->usedUnicode as $unicode => &$x) {
            $origGID = $cmap->getGid($unicode);
            $usedGID[$origGID] = 1;
            $x = ++$newGID;
        }
        $data = $this->font->subset($usedGID);
        $this->fontProgramSize = strlen($data);
        $this->subsetFont = gzcompress($data, Config::GZIP_LEVEL);
        return true;
    }

    //========以下為 subset 後會被呼叫========

    /**
     * 回傳字型真正的名稱(不一定是檔名)
     * 
     * @return string 字型名稱
     */
    public function getPostscriptName()
    {
        return $this->fontname;
    }

    /**
     * 回傳字型資料(用 gzcompress 壓縮後的)
     * 
     * @return string 字型資料
     */
    public function getProgram()
    {
        return $this->subsetFont;
    }

    /**
     * 取得使用到文字的 utw 映射表
     * 這會用來產生 pdf Font 的 /W entry
     * 雖然全字型的也可
     * 但只取 subset 檔案較小
     * 
     * @return array 回傳 utw 映射表
     */
    public function getW()
    {
        $cmap = $this->cmap;
        $hmtx = $this->hmtx;
        $scale = 1000 / $this->unitsPerEm;
        $result = [];
        foreach ($this->usedUnicode as $unicode => $newGID) {
            $gid = $cmap->getGid($unicode);
            if ($gid > 0) {
                $w = $hmtx->getWidth($gid);
                $result[$newGID] = $w ? $w * $scale : 1000;
            }
        }
        return $result;
    }

    /**
     * 取得使用到文字的 ctu 映射表
     * 這會用來產生 pdf Font 的 /ToUnicode entry
     * 
     * @return array 回傳 cid => unicode 映射表
     */
    public function getCTU()
    {
        $result = [];
        foreach ($this->usedUnicode as $unicode => $newGID) {
            $result[$newGID] = $unicode;
        }
        return $result;
    }

    /**
     * 回傳字型資訊，包含：
     * getMtx() 的所有內容外加 size
     * size: subset後字型檔的原始大小(未壓縮前)
     * 
     * @return array 字型資訊
     */
    public function getInfo()
    {
        $arr = $this->mtx;
        $arr['size'] = $this->fontProgramSize;
        return $arr;
    }

    /**
     * 依照 subset 後，新生成的 utc 表
     * 將字串轉換為對應的 hex string
     * 
     * @param string $str UTF-16BE 編碼的字串
     * @return string 作為 pdf 文字內容的 hex-string
     */
    public function getText($str)
    {
        $arr = array_values(unpack('n*', $str));
        $n = count($arr);
        $s = '';
        for ($i = 0; $i < $n; ++$i) {
            $c = $arr[$i];
            if (0xd800 <= $c && $c <= 0xdbff) {
                $t = $arr[++$i];
                $c = ($c - 0xd800 << 10 | $t - 0xdc00) + 0x10000;
            }
            $c = $this->usedUnicode[$c] ?? 0;
            $s .= str_pad(dechex($c), 4, '0', STR_PAD_LEFT);
        }
        return $s;
    }
}
