<?php
namespace ren1244\PDFWriter\Module;

use ren1244\PDFWriter\StreamWriter;
use ren1244\PDFWriter\Resource\FontController;
use ren1244\PDFWriter\PageMetrics;

class Text implements ModuleInterface
{
    private $ftCtrl;
    private $mtx;

    public function __construct(FontController $ftCtrl, PageMetrics $mtx)
    {
        $this->ftCtrl=$ftCtrl;
        $this->mtx=$mtx;
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
        $this->x=PageMetrics::getPt($x);
        $this->y=PageMetrics::getPt($y);
        $this->width=PageMetrics::getPt($width);
        $this->height=PageMetrics::getPt($height);
    }

    /**
     * 加入文字
     * 
     * @param string $text 文字(utf-8)
     * @param array $opt 選項
     *                  lineHeight(number): 行高
     *                  breakWord(bool): 英數強制換行
     *                  color(string): 顏色，RRGGBB，例如 FFCC00
     *                  textAlign(string): 文字對齊(left、center、right)
     *                  cellAlign(integer): 格子內對齊位置（數字鍵的位置）
     *                  underline(number): 底線寬(pt)
     * @return void
     */
    public function addText($text, $opt=[])
    {
        $width=$this->width;
        $height=$this->height;
        $ftCtrl=$this->ftCtrl;
        $lineHeightScale=$opt['lineHeight']??1.2;
        $hInfo=$ftCtrl->getFontHeightInfo();
        $wordBreak=$opt['wordBreak']??false;
        $maxWidth=$maxHeight=0;
        $lineArr=[];
        $widthArr=[];
        $heightArr=[];
        $textArr=explode("\n", $text);
        foreach($textArr as $text) {
            $text=mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
            $len=strlen($text);
            $idx=0;
            while(($line=$this->getNextLine($text, $len, $idx, $totalWidth, $hInfo, $lineHeight, $wordBreak))!==null) {
                $lineHeight*=$lineHeightScale;
                if($maxHeight+$lineHeight>$height) {
                    break;
                }
                if($maxWidth<$totalWidth) {
                    $maxWidth=$totalWidth;
                }
                $maxHeight+=$lineHeight;
                $lineArr[]=$line;
                $widthArr[]=$totalWidth;
                $heightArr[]=$lineHeight;
            }
        }
        //框內對齊
        $cellAlign=$opt['cellAlign']??7;
        $textAlign=$opt['textAlign']??'left';
        if($cellAlign%3===0) {
            $dx=$width-$maxWidth;
        } elseif($cellAlign%3===2) {
            $dx=($width-$maxWidth)/2;
        } else {
            $dx=0;
        }
        if((($cellAlign-1)/3|0)===0) { //靠下
            $dy=$this->height-$maxHeight;
        } elseif((($cellAlign-1)/3|0)===1) { //居中
            $dy=($this->height-$maxHeight)/2;
        } else {
            $dy=0;
        }
        $x0=$this->x+$dx;
        $y=$this->mtx->height-($this->y+$dy);
        foreach($lineArr as $idx=>$line) {
            //文字對齊
            if($textAlign==='right') {
                $dtx=$maxWidth-$widthArr[$idx];
            } elseif($textAlign==='center') {
                $dtx=($maxWidth-$widthArr[$idx])/2;
            } else {
                $dtx=0;
            }
            //行高
            $lineHeight=$heightArr[$idx];
            //此時 (x,y) 為左上角座標
            $x=$x0+$dtx;
            $color=isset($opt['color'])?$this->convertRGBValue($opt['color']):false;
            foreach($line as $seg) {
                $info=$hInfo[$seg['font']];
                $helfLeading=($lineHeight-$info['height'])/2;
                $tmpY=$y-$helfLeading-$info['ascent'];
                if(isset($opt['underline']) && floatval($opt['underline'])>0) {
                    $tmpY2=$tmpY+$info['descent'];
                    $underline=' '.($color?$color.' RG ':'')."${opt['underline']} w $x $tmpY2 m ".($x+$widthArr[$idx])." $tmpY2 l S ";
                } else {
                    $underline='';
                }
                $this->mtx->pushData($this, [
                    'psName'=>$seg['font'],
                    'size'=>$info['size'],
                    'x'=>$x,
                    'y'=>$tmpY,
                    'str'=>$seg['text'],
                    'color'=>$color?$color.' rg ':'',
                    'underline'=>$underline,
                ]);
                $x+=$seg['width'];
            }
            $y-=$lineHeight;
        }
    }

    /**
     * 把字串分割成限定寬度(小於等於寬度的最大值)的片段
     * 若第一個字就超過寬度，則至少會寫入第一個字(允許超過)
     * 
     * @param string $text 字串(utf-16be)
     * @param int $len 字串總長度(byte)
     * @param int &$idx 讀取到的位置
     * @param number &$totalWidth 該行總寬度(所有片段的 width 相加)
     * @param array $fontHeight {font:{height:行高},...}
     * @param number &$lineHeight 行高(該行有使用到的字型的最大值)
     * @param bool $wordBreak 是否強制英數斷航
     * @return array|null 該行的資料，[{text:字串(utf-16be),width:寬度,font:字型},...]
     */
    private function getNextLine($text, $len, &$idx, &$totalWidth, $fontHeight, &$lineHeight ,$wordBreak=false)
    {
        $ftCtrl=$this->ftCtrl;
        $maxWidth=$this->width;
        $lineHeight=0;
        $result=[];
        $i0=$i1=$i2=$idx;
        $w0=$w1=$w2=$w=$dIdx=0;
        if($i2>=$len) {
            return null;
        }
        while($i2<$len) {
            do {
                if($i2+1>=$len) {
                    throw new \Exception('bad UTF-16BE encoding');
                }
                $c=(ord($text[$i2])<<8|ord($text[$i2+1]));
                if(0xd800<=$c && $c<=0xdbff) {
                    if($i2+3>=$len) {
                        throw new \Exception('bad UTF-16BE encoding');
                    }
                    $t=(ord($text[$i2+2])<<8|ord($text[$i2+3]));
                    $c=($c-0xd800<<10|$t-0xdc00)+0x10000;
                    $dIdx=4;
                } else {
                    $dIdx=2;
                }
                $w=$ftCtrl->getWidth($c, $ft);
                $isWordChar=(48<=$c && $c<=57)||(65<=$c && $c<=90)||(97<=$c && $c<=122)?true:false;
                if($i2===$i1) {
                    $i2+=$dIdx;
                    $w2=$w;
                    $ft2=$ft;
                } elseif($isWordChar) {
                    if($ft2!==$ft) {
                        throw new \Exception('[未完成]英數字中間換字型');
                    }
                    $i2+=$dIdx;
                    $w2+=$w;
                }
            } while($isWordChar && $i2<$len && !$wordBreak && $w0+$w1+$w2<=$maxWidth);
            if($w0+$w1+$w2>$maxWidth) {
                break;
            }
            if($i1===$i0) {
                $ft1=$ft2;
            } elseif($ft1!==$ft2) { //字型改變
                $result[]=[
                    'text' => substr($text, $i0, $i1-$i0),
                    'width' => $w1,
                    'font' => $ft1
                ];
                if($lineHeight<$fontHeight[$ft1]['height']) {
                    $lineHeight=$fontHeight[$ft1]['height'];
                }
                $i0=$i1;
                $w0+=$w1;
                $w1=0;
                $ft1=$ft2;
            }
            $i1=$i2;
            $w1+=$w2;
            $w2=0;
        }
        if($i1>$i0) {
            $result[]=[
                'text' => substr($text, $i0, $i1-$i0),
                'width' => $w1,
                'font' => $ft1
            ];
            if($lineHeight<$fontHeight[$ft1]['height']) {
                $lineHeight=$fontHeight[$ft1]['height'];
            }
            $i0=$i1;
            $w0+=$w1;
        } elseif($w0+$w1===0) { //至少要一個字
            if($i2-$dIdx>$idx) {
                $i2-=$dIdx;
                $w2-=$w;
            }
            $result[]=[
                'text' => substr($text, $idx, $i2-$idx),
                'width' => $w2,
                'font' => $ft
            ];
            if($lineHeight<$fontHeight[$ft]['height']) {
                $lineHeight=$fontHeight[$ft]['height'];
            }
            $i0=$i1=$i2;
            $w0=$w2;
            $w1=$w2=0;
        }
        $idx=$i0;
        $totalWidth=$w0;
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
        $val=hexdec($colorStr);
        $r=($val>>16&0xff)/255;
        $g=($val>>8&0xff)/255;
        $b=($val&0xff)/255;
        return "$r $g $b";
    }

    /**
     * 這個函式不由使用者呼叫
     */
    public function write(StreamWriter $writer, array $datas)
    {
        $s=[];
        foreach($datas as $data) {
            $str=$this->ftCtrl->getTextContent($data['psName'], $data['str']);
            $ftName=$this->ftCtrl->getFontName($data['psName']);
            $s[]="q BT /$ftName ${data['size']} Tf ${data['color']}${data['x']} ${data['y']} Td <$str> Tj ET".$data['underline'].' Q';
        }
        return $writer->writeStream(implode(' ', $s), StreamWriter::COMPRESS);
    }
}