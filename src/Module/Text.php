<?php

namespace ren1244\PDFWriter\Module;

use Exception;
use ren1244\PDFWriter\StreamWriter;
use ren1244\PDFWriter\Resource\FontController;
use ren1244\PDFWriter\PageMetrics;
use ren1244\PDFWriter\Module\Text\Line_V;
use ren1244\PDFWriter\Module\Text\Word_V;

class Text implements ModuleInterface
{
    private $ftCtrl;
    private $mtx;

    public function __construct(FontController $ftCtrl, PageMetrics $mtx)
    {
        $this->ftCtrl = $ftCtrl;
        $this->mtx = $mtx;
        $this->setRect(0, 0, $mtx->width, $mtx->height);
    }

    private $x; //左上角 x 座標
    private $y; //左上角 y 座標
    private $width; //矩形區域寬度
    private $height; //矩形區域高度
    /**
     * 設定寫入的區域（單位與使用者設定的單位相同）
     * 
     * @param number $x 左上角 x 座標
     * @param number $y 左上角 y 座標
     * @param number $width 矩形區域寬度
     * @param number $height 矩形區域高度
     * @return void
     */
    public function setRect($x, $y, $width, $height)
    {
        $this->x = PageMetrics::getPt($x);
        $this->y = PageMetrics::getPt($y);
        $this->width = PageMetrics::getPt($width);
        $this->height = PageMetrics::getPt($height);
    }

    /**
     * 加入文字(橫書)
     * 
     * @param string $text 文字(utf-8)
     * @param array $opt 選項
     *                  lineHeight(number): 行高
     *                  wordBreak(bool): 英數強制換行
     *                  color(string): 顏色，RRGGBB，例如 FFCC00
     *                  textAlign(string): 文字對齊(left、center、right)
     *                  cellAlign(integer): 格子內對齊位置（數字鍵的位置）
     *                  underline(number): 底線寬(pt)
     * @return void
     */
    public function addText($text, $opt = [])
    {
        $width = $this->width;
        $height = $this->height;
        $ftCtrl = $this->ftCtrl;
        $lineHeightScale = $opt['lineHeight'] ?? 1.2;
        $hInfo = $ftCtrl->getFontHeightInfo();
        $wordBreak = $opt['wordBreak'] ?? false;
        $maxWidth = $maxHeight = 0; //真正用到的文字範圍
        $lineArr = [];   //每行資料，getNextLine 的回傳值
        $widthArr = [];  //每行總寬度
        $heightArr = []; //每行高度
        $baselineToTopArr = []; //基線的位置(與目前y座標的距離)
        $underlineToTopArr = []; //畫底線的位置(與目前y座標的距離)
        $textArr = explode("\n", $text);
        foreach ($textArr as $text) {
            $text = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
            $len = strlen($text);
            $idx = 0;
            while (($line = $this->getNextLine(
                $text,
                $len,
                $idx,
                $totalWidth,
                $hInfo,
                $lineAscender,
                $lineDescender,
                $lineLineGap,
                $wordBreak
            )) !== null) {
                $baseHeight = $lineAscender - $lineDescender;
                $lineHeight = ($baseHeight + $lineLineGap) * $lineHeightScale;
                if ($maxHeight + $lineHeight > $height) {
                    break;
                }
                if ($maxWidth < $totalWidth) {
                    $maxWidth = $totalWidth;
                }
                $maxHeight += $lineHeight;
                $lineArr[] = $line;
                $widthArr[] = $totalWidth;
                $heightArr[] = $lineHeight;
                $baselineToTopArr[] = $baselineToTop = ($lineHeight - $baseHeight) / 2 + $lineAscender;
                $underlineToTopArr[] = $baselineToTop - $lineDescender;
            }
        }
        //框內對齊
        $cellAlign = $opt['cellAlign'] ?? 7;
        $textAlign = $opt['textAlign'] ?? 'left';
        if ($cellAlign % 3 === 0) {
            $dx = $width - $maxWidth;
        } elseif ($cellAlign % 3 === 2) {
            $dx = ($width - $maxWidth) / 2;
        } else {
            $dx = 0;
        }
        if ((($cellAlign - 1) / 3 | 0) === 0) { //靠下
            $dy = $this->height - $maxHeight;
        } elseif ((($cellAlign - 1) / 3 | 0) === 1) { //居中
            $dy = ($this->height - $maxHeight) / 2;
        } else {
            $dy = 0;
        }
        $x0 = $this->x + $dx;
        $y = $this->mtx->height - ($this->y + $dy);
        foreach ($lineArr as $idx => $line) {
            //文字對齊
            if ($textAlign === 'right') {
                $dtx = $maxWidth - $widthArr[$idx];
            } elseif ($textAlign === 'center') {
                $dtx = ($maxWidth - $widthArr[$idx]) / 2;
            } else {
                $dtx = 0;
            }
            //此時 (x,y) 為左上角座標
            $x = $x0 + $dtx;
            $color = isset($opt['color']) ? $this->convertRGBValue($opt['color']) : false;
            foreach ($line as $seg) {
                if (isset($opt['underline']) && floatval($opt['underline']) > 0) {
                    $liderlineY = $y - $underlineToTopArr[$idx];
                    $underline = ' ' . ($color ? $color . ' RG ' : '') . "${opt['underline']} w $x $liderlineY m " . ($x + $seg['width']) . " $liderlineY l S ";
                } else {
                    $underline = '';
                }
                $this->mtx->pushData($this, [
                    'psName' => $seg['font'],
                    'size' => $hInfo[$seg['font']]['size'],
                    'x' => $x,
                    'y' => $y - $baselineToTopArr[$idx],
                    'str' => $seg['text'],
                    'color' => $color ? $color . ' rg ' : '',
                    'underline' => $underline,
                ]);
                $x += $seg['width'];
            }
            $y -= $heightArr[$idx];
        }
    }

    /**
     * 加入文字(直書)
     * 
     * @param string $text 文字(utf-8)
     * @param array $opt 選項
     *                  lineSpace(number): 行距
     *                  wordSpace(number): 字距
     *                  mode(string): 從左到右或是從右到左("RL", "LR")
     *                  color(string): 顏色，RRGGBB，例如 FFCC00
     *                  textAlign(string): 文字對齊(left、center、right)
     *                  cellAlign(integer): 格子內對齊位置（數字鍵的位置）
     * @return string 剩餘字串
     */
    public function addTextV($text, $opt = [])
    {
        // 作為空行時的最小行寬
        $minWidth = null;
        $ftInfo = $this->ftCtrl->getFontHeightInfo();
        foreach ($ftInfo as $ft) {
            if ($minWidth === null || $minWidth > $ft['size']) {
                $minWidth = $ft['size'];
            }
        }
        // 行距
        $lineSpace = $opt['lineSpace'] ?? 0;
        // 字距
        $wordSpace = $opt['wordSpace'] ?? 0;
        // 顏色
        $textColor = $this->convertRGBValue($opt['color'] ?? '000000');
        // 取得 lineArray
        Line_V::setFontController($this->ftCtrl);
        $lineArray = $this->getLines(
            $text,
            Line_V::class,
            $this->height,
            $wordSpace,
            $this->width,
            $lineSpace,
            $minWidth
        );
        // 計算位置
        if (($n = count($lineArray)) > 0) {
            $mode = $opt['mode'] ?? 'RL';
            // 計算 cell 大小
            $cellW = $lineSpace * ($n - 1);
            $cellH = 0;
            foreach ($lineArray as $line) {
                $cellW += $line->getLength2();
                $h = $line->getLength1();
                if ($cellH < $h) {
                    $cellH = $h;
                }
            }
            // 計算起始 x 位置
            $cellAlign = $opt['cellAlign'] ?? ($mode === 'RL' ? 9 : 7);
            if ($cellAlign % 3 === 0) { // 靠右
                $x = $this->x + $this->width - $cellW;
            } elseif ($cellAlign % 3 === 2) { // 置中
                $x = $this->x + ($this->width - $cellW) / 2;
            } else { // 靠左
                $x = $this->x;
            }
            if ($mode !== 'LR') {
                $x += $cellW;
                $dxMode = -1;
            } else {
                $dxMode = 1;
            }
            // 計算 cell 左上角的 y 值於 y0
            $y0 = $this->mtx->height - $this->y; // rect 的 y
            if ($cellAlign > 6) { // 靠上
                // y0 不動
            } elseif ($cellAlign > 3) { // 置中
                $y0 +=  ($cellH - $this->height) / 2;
            } else { // 靠下
                $y0 += $cellH - $this->height;
            }
            // 依據 textAline 調整基準線 y0
            $textAlign = $opt['textAlign'] ?? 'start';
            if ($textAlign === 'middle') {
                $y0 -= $cellH / 2;
            } elseif ($textAlign === 'end') {
                $y0 -= $cellH;
            }
            // 寫入 line 的座標
            foreach ($lineArray as $line) {
                // 設定 x
                $dx = $line->getLength2() * $dxMode;
                //echo "[$dx]";
                $line->x = $x + $dx / 2;
                $x += $dx + $lineSpace * $dxMode;
                // 設定 y
                if ($textAlign === 'middle') {
                    $line->y = $y0 + $line->getLength1() / 2;
                } elseif ($textAlign === 'end') {
                    $line->y = $y0 + $line->getLength1();
                } else {
                    $line->y = $y0;
                }
            }
            $this->mtx->pushData($this, [
                'lines' => $lineArray,
                'color' => $textColor,
            ]);
        }
        return $text;
    }

    /**
     * getLines
     *
     * @param  mixed &$text     文字
     * @param  mixed $lineClass 使用的 Line 物件
     * @param  mixed $length1   行長度最大值
     * @param  mixed $space1    字距
     * @param  mixed $length2   多行並排長度的最大值
     * @param  mixed $space2    行距
     * @param  mixed $minOffset 多行並排的最小偏移
     * @return array
     */
    private function getLines(&$text, $lineClass, $length1, $space1, $length2, $space2, $minOffset)
    {
        $line = new $lineClass($length1, $space1, $minOffset);
        $lineArray = [];
        $firstLineFlag = true;
        $totalLen2 = 0; // lineArray 中 length2 的總和
        $len = strlen($text);
        $i = $readIdx = $lineReadIdx = 0;
        while ($i < $len) {
            // 取得 unicode 於 $c
            if (($c = ord($text[$i])) < 0xe0) {
                if ($c < 0xe0) {
                    $nByte = 1;
                } else {
                    $c = ($c & 0x1f) << 6 | ord($text[$i + 1]) & 0x3f;
                    $nByte = 2;
                }
            } else {
                if ($c < 0xf0) {
                    $c = ($c & 0x0f) << 12 | (ord($text[$i + 1]) & 0x3f) << 6 | ord($text[$i + 2]) & 0x3f;
                    $nByte = 3;
                } else {
                    $c = ($c & 0x07) << 18 | (ord($text[$i + 1]) & 0x3f) << 12 | (ord($text[$i + 2]) & 0x3f) << 6 | ord($text[$i + 3]) & 0x3f;
                    $nByte = 4;
                }
            }
            // 推送到 Word，並依回傳狀態處理
            $x = $line->push($c);
            if ($x & $lineClass::ACCEPT) {
                $readIdx = $i;
            }
            if ($x & $lineClass::NEXT) {
                $i += $nByte;
                if ($x & $lineClass::ENDLINE) {
                    $readIdx = $i;
                }
            } elseif ($x & $lineClass::DISCARD) {
                $i = $readIdx;
            }
            if ($i === $len) {
                $x = $line->pullWord() | $lineClass::ENDLINE;
                if ($x & $lineClass::ACCEPT) {
                    $readIdx = $i;
                } elseif ($x & $lineClass::DISCARD) {
                    $i = $readIdx;
                }
            }
            if ($x & $lineClass::ENDLINE) {
                if ($firstLineFlag) {
                    $firstLineFlag = false;
                    $offset = $line->getLength2();
                } else {
                    $offset = $line->getLength2() + $space2;
                }
                if ($totalLen2 + $offset < $length2) {
                    $lineArray[] = $line;
                    $line = new $lineClass($length1, $space1, $minOffset);
                    $totalLen2 += $offset;
                    $lineReadIdx = $readIdx;
                } else {
                    break;
                }
            }
        }
        $text = substr($text, $lineReadIdx);
        return $lineArray;
    }


    /**
     * 把字串分割成限定寬度(小於等於寬度的最大值)的片段
     * 若第一個字就超過寬度，則至少會寫入第一個字(允許超過)
     * 
     * @param string $text 字串(utf-16be)
     * @param int $len 字串總長度(byte)
     * @param int &$idx 讀取到的位置
     * @param float &$totalWidth 該行總寬度(所有片段的 width 相加)
     * @param array $fontHeight {font:{height:行高},...}
     * @param float &$lineAscender  回傳最高的 Ascender
     * @param float &$lineDescender 回傳最低的 Descender
     * @param float &$lineLineGap   回傳最大的 LineGap
     * @param bool $wordBreak 是否強制英數斷行
     * @return array|null 該行的資料，[{text:字串(utf-16be),width:寬度,font:字型},...]
     */
    private function getNextLine(
        $text,
        $len,
        &$idx,
        &$totalWidth,
        $fontHeight,
        &$lineAscender,
        &$lineDescender,
        &$lineLineGap,
        $wordBreak = false
    ) {
        $ftCtrl = $this->ftCtrl;
        $maxWidth = $this->width;
        $lineAscender = $lineDescender = $lineLineGap = 0;
        $result = [];
        $i0 = $i1 = $i2 = $idx;
        $w0 = $w1 = $w2 = $w = $dIdx = 0;
        $ft = $ft1 = $ft2 = '';
        if ($i2 >= $len) {
            return null;
        }
        while ($i2 < $len) {
            do {
                if ($i2 + 1 >= $len) {
                    throw new \Exception('bad UTF-16BE encoding');
                }
                $c = (ord($text[$i2]) << 8 | ord($text[$i2 + 1]));
                if (0xd800 <= $c && $c <= 0xdbff) {
                    if ($i2 + 3 >= $len) {
                        throw new \Exception('bad UTF-16BE encoding');
                    }
                    $t = (ord($text[$i2 + 2]) << 8 | ord($text[$i2 + 3]));
                    $c = ($c - 0xd800 << 10 | $t - 0xdc00) + 0x10000;
                    $dIdx = 4;
                } else {
                    $dIdx = 2;
                }
                $w = $ftCtrl->getWidth($c, $ft);
                $isWordChar = (48 <= $c && $c <= 57) || (65 <= $c && $c <= 90) || (97 <= $c && $c <= 122) ? true : false;
                if ($i2 === $i1) {
                    $i2 += $dIdx;
                    $w2 = $w;
                    $ft2 = $ft;
                } elseif ($isWordChar) {
                    if ($ft2 !== $ft) {
                        throw new \Exception('[未完成]英數字中間換字型');
                    }
                    $i2 += $dIdx;
                    $w2 += $w;
                }
            } while ($isWordChar && $i2 < $len && !$wordBreak && $w0 + $w1 + $w2 <= $maxWidth);
            if ($w0 + $w1 + $w2 > $maxWidth) {
                break;
            }
            if ($i1 === $i0) {
                $ft1 = $ft2;
            } elseif ($ft1 !== $ft2) { //字型改變
                $result[] = [
                    'text' => substr($text, $i0, $i1 - $i0),
                    'width' => $w1,
                    'font' => $ft1
                ];
                if ($lineAscender < $fontHeight[$ft1]['typoAscender']) {
                    $lineAscender = $fontHeight[$ft1]['typoAscender'];
                }
                if ($lineDescender > $fontHeight[$ft1]['typoDescender']) {
                    $lineDescender = $fontHeight[$ft1]['typoDescender'];
                }
                if ($lineLineGap < $fontHeight[$ft1]['typoLineGap']) {
                    $lineLineGap = $fontHeight[$ft1]['typoLineGap'];
                }
                $i0 = $i1;
                $w0 += $w1;
                $w1 = 0;
                $ft1 = $ft2;
            }
            $i1 = $i2;
            $w1 += $w2;
            $w2 = 0;
        }
        if ($i1 > $i0) {
            $result[] = [
                'text' => substr($text, $i0, $i1 - $i0),
                'width' => $w1,
                'font' => $ft1
            ];
            if ($lineAscender < $fontHeight[$ft1]['typoAscender']) {
                $lineAscender = $fontHeight[$ft1]['typoAscender'];
            }
            if ($lineDescender > $fontHeight[$ft1]['typoDescender']) {
                $lineDescender = $fontHeight[$ft1]['typoDescender'];
            }
            if ($lineLineGap < $fontHeight[$ft1]['typoLineGap']) {
                $lineLineGap = $fontHeight[$ft1]['typoLineGap'];
            }
            $i0 = $i1;
            $w0 += $w1;
        } elseif ($w0 + $w1 === 0) { //至少要一個字
            if ($i2 - $dIdx > $idx) {
                $i2 -= $dIdx;
                $w2 -= $w;
            }
            $result[] = [
                'text' => substr($text, $idx, $i2 - $idx),
                'width' => $w2,
                'font' => $ft
            ];
            if ($lineAscender < $fontHeight[$ft]['typoAscender']) {
                $lineAscender = $fontHeight[$ft]['typoAscender'];
            }
            if ($lineDescender > $fontHeight[$ft]['typoDescender']) {
                $lineDescender = $fontHeight[$ft]['typoDescender'];
            }
            if ($lineLineGap < $fontHeight[$ft]['typoLineGap']) {
                $lineLineGap = $fontHeight[$ft]['typoLineGap'];
            }
            $i0 = $i1 = $i2;
            $w0 = $w2;
            $w1 = $w2 = 0;
        }
        $idx = $i0;
        $totalWidth = $w0;
        return $result;
    }

    /**
     * 把 16 進位的 RGB 轉換成三個浮點數構成的字串
     * 
     * @param string 顏色值，例如 "FFFFFF"
     * @return string 顏色值，例如 "1 0.5 1"
     */
    private function convertRGBValue($colorStr)
    {
        $val = hexdec($colorStr);
        $r = ($val >> 16 & 0xff) / 255;
        $g = ($val >> 8 & 0xff) / 255;
        $b = ($val & 0xff) / 255;
        return "$r $g $b";
    }

    /**
     * 這個函式不由使用者呼叫
     */
    public function write(StreamWriter $writer, array $datas)
    {
        $s = [];
        foreach ($datas as $data) {
            if (isset($data['lines'])) {
                $rgb = $data['color'];
                $s[] = "q $rgb rg";
                foreach ($data['lines'] as $line) {
                    $s[] = 'q ' . $line->postscript() . ' Q';
                }
                $s[] = 'Q';
            } else {
                $str = $this->ftCtrl->getTextContent($data['psName'], $data['str']);
                $ftName = $this->ftCtrl->getFontName($data['psName']);
                $s[] = "q BT /$ftName {$data['size']} Tf {$data['color']}{$data['x']} {$data['y']} Td <$str> Tj ET" . $data['underline'] . ' Q';
            }
        }
        return $writer->writeStream(implode(' ', $s), StreamWriter::COMPRESS);
    }
}
