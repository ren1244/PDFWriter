<?php

namespace ren1244\PDFWriter\FontLib;

use ren1244\PDFWriter\Config;
use ren1244\sfnt\Sfnt;
use ren1244\sfnt\TypeReader;

class OpenType implements Font
{
    private $reader;
    private $font;

    private $fontname;
    private $unitsPerEm;
    private $unicodeToGID = [];
    private $GIDToWidth = [];
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
        $fontContent = file_get_contents(Config::FONT_DIR . '/custom/' . $fontFilename . '.bin');
        $this->reader = new TypeReader($fontContent);
        $this->font = new Sfnt($this->reader);
        // 讀取 head
        $head = $this->font->table('head');
        $this->unitsPerEm = $head->unitsPerEm;
        // 從 cmap 取得 unicodeToGID
        $cmap = $this->font->table('cmap');
        $cmap->getCodeToGid(3, 10, $this->unicodeToGID);
        $cmap->getCodeToGid(3, 1, $this->unicodeToGID);
        // 
        $cff = $this->font->table('CFF ');
        //$cff->setCharstringDependancyCache($fontCache);
        $this->fontname = $cff->fontname;
        $fontBox = $cff->topDict['FontBBox'];
        // 讀取 maxp
        $maxp = $this->font->table('maxp');
        // 讀取 hhea
        $hhea = $this->font->table('hhea');
        // 從 hmtx 取得 GIDToWidth
        $this->GIDToWidth = $this->font->table('hmtx')->getGIDToWidth(
            $hhea->numberOfHMetrics,
            $maxp->numGlyphs
        );
        $os2 = $this->font->table('OS/2');
        $scale = 1000 / $head->unitsPerEm;

        $this->mtx = [
            'bbox' => [
                $fontBox[0] * $scale,
                $fontBox[1] * $scale,
                $fontBox[2] * $scale,
                $fontBox[3] * $scale
            ],
            'italicAngle' => $this->font->table('post')->italicAngle * $scale,
            'ascent' => $hhea->ascender * $scale,
            'descent' => $hhea->descender * $scale,
            'capHeight' => $os2->sCapHeight * $scale,
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
        if (!isset($this->unicodeToGID[$unicode])) {
            return false;
        }
        $this->usedUnicode[$unicode] = 1;
        return $this->GIDToWidth[$this->unicodeToGID[$unicode]];
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
        return 2;
    }

    /**
     * 依據曾經 getWidth 的 unicodes，建立 subset
     */
    public function subset()
    {
        if(empty($this->usedUnicode)) {
            return false;
        }
        // head 與 NAME INDEX 不變
        // Top Dict INDEX 要重新設定位置(這邊位置計算應該不容易)
        // string INDEX 與 gSubr INDEX 可直接複製
        // 
        ksort($this->usedUnicode, SORT_NUMERIC);
        $cff = $this->font->table('CFF ');
        $newGID = 0;
        $gidMap = [0 => 0]; // 舊 => 新
        foreach ($this->usedUnicode as $unicode => $x) {
            $gid = $this->unicodeToGID[$unicode]; // 舊的 GID
            if (!isset($gidMap[$gid])) {
                $cff->setUsed($gid);
                $this->usedUnicode[$unicode] = ++$newGID;
                $gidMap[$gid] = $newGID;
            } else {
                $this->usedUnicode[$unicode] = $gidMap[$gid];
            }
        }
        $data = $cff->subset();
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
     * 取得使用到文字的 CID => width 映射表
     * 這會用來產生 pdf Font 的 /W entry
     * 不管字型有沒有 subset，可以只回傳使用到的 code 部分
     * 
     * @return array 回傳 CID => width 映射表
     */
    public function getW()
    {
        $utg = $this->unicodeToGID;
        $gtw = $this->GIDToWidth;
        $scale = 1000 / $this->unitsPerEm;
        $result = [];
        foreach ($this->usedUnicode as $unicode => $newGID) {
            $gid = $utg[$unicode] ?? 0;
            if ($gid > 0) {
                $result[$newGID] = $gtw[$gid] ? $gtw[$gid] * $scale : 1000;
            }
        }
        return $result;
    }

    /**
     * 取得使用到文字的 code => unicode 映射表
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
        $utg = $this->unicodeToGID;
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
