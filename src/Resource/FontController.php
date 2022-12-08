<?php
namespace ren1244\PDFWriter\Resource;

use ren1244\PDFWriter\Config;
use ren1244\PDFWriter\StreamWriter;

/**
 * 字型管理器
 */
class FontController implements ResourceInterface
{
    /**
     * 名詞定義：
     *     1. 字型名稱：使用者使用的字型名稱，可以是13種標準字型。
     *                 或是自己建立的字型（等同 font 資料夾的檔案名稱）。
     *                 例如：Times-Roman、SourceHanSans 等。
     *     2. PDF字型名稱：在組 content stream 時使用的字型名稱。例如： FT1、FT2等。
     */
    private $fonts=[];
    private $fontCount=0;
    private $curFont=false; //ftName
    private $curName=false; //pdf font name
    private $ft2name=[]; //字型檔案的名稱 => PDF內字型名稱
    private $getWidthType=false;
    private $ftSize=12;
    private $subsetId=0;
    private $fontHeightInfo;

    private static $standardFontName=[
        'Times-Roman', 'Times-Bold', 'Times-Italic', 'Times-BoldItalic',
        'Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique',
        'Courier', 'Courier-Bold', 'Courier-Oblique', 'Courier-BoldOblique',
        'Symbol'
    ];

    //============ 以下函式提供給使用者呼叫 ============

    /**
     * 在 PDF 中添加字型
     * 
     * @param string $ftName 字型名稱。
     * @return void
     */
    public function addFont($ftName)
    {
        if(!isset($this->fonts[$ftName])) {
            if(in_array($ftName, self::$standardFontName)) {
                $ft=new \ren1244\PDFWriter\FontLib\StandardFont($ftName);
            } else {
                $jsonFile=Config::FONT_DIR.'/custom/'.$ftName.'.json';
                if(!file_exists($jsonFile)) {
                    throw new \Exception("TrueType font $ftName not exsits");
                }
                $jsonData=json_decode(file_get_contents($jsonFile), true);
                if($jsonData['type']==='OTF') {
                    $ft=new \ren1244\PDFWriter\FontLib\OpenType($ftName, $jsonData);
                } elseif($jsonData['type']==='TTF') {
                    $ft=new \ren1244\PDFWriter\FontLib\TrueType($ftName, $jsonData);
                } else {
                    throw new \Exception('bad type of '.$ftName.'.json ');
                }
            }
            $this->fonts[$ftName]=$ft;
            $nameId=++$this->fontCount;
            $this->ft2name[$ftName]="FT$nameId";
            $this->setFont($ftName);
        }
    }

    /**
     * 設定目前使用的字型
     * 
     * @param string|array $font 要使用的字型名稱
     *                     如果是陣列，格式為 [字型名稱 => 字型大小, ...]，越前面會優先使用。
     * @param int $size 字型大小，只在 $font 為字串時使用
     * @return void
     */
    public function setFont($font, $size=false)
    {
        if($size===false){
            $size=$this->ftSize;
        }
        if(is_array($font)) {
            $this->getWidthType=2;
            $this->curFont=$font;
            $this->fontHeightInfo=[];
            foreach($font as $ft=>$sz) {
                $info=$this->fonts[$ft]->getMtx();
                $this->fontHeightInfo[$ft]=[
                    'size' => $sz,
                    'ascent' => $info['ascent']*$sz/1000,
                    'descent' => $info['descent']*$sz/1000,
                    'height' => ($info['ascent']-$info['descent'])*$sz/1000,
                ];
            }
        } else {
            $this->getWidthType=1;
            $this->curFont=$font;
            $this->ftSize=$size;
            $this->curName=$this->getFontName($font);
            $info=$this->fonts[$this->curFont]->getMtx();
            $this->fontHeightInfo=[
                $font => [
                    'size' => $size,
                    'ascent' => $info['ascent']*$size/1000,
                    'descent' => $info['descent']*$size/1000,
                    'height' => ($info['ascent']-$info['descent'])*$size/1000,
                ]
            ];
        }
    }

    //============ 以下函式提供給 Content Module 呼叫 ============

    /**
     * 取得「PDF字型名稱」
     * 
     * @param string $ftName 字型名稱
     * @return string PDF字型名稱
     */
    public function getFontName($ftName)
    {
        return $this->ft2name[$ftName]??false;
    }

    /**
     * 依據 setFont 設定的字型取得字的寬度與PDF字型名稱
     * （只回傳寬度，然後第二個參數會被設定為PDF字型名稱）
     * 注意：這個函式同時也註冊有哪些文字會被使用，作為 subset 的參考
     * 
     * @param int $unicode unicode code point
     * @param mixed &$ftName 如果找到字，會被設定為字型名稱，否則這個值無意義
     * @return int|false 字的寬度，如果找不到回傳 false
     */
    public function getWidth($unicode, &$ftName)
    {
        if($this->getWidthType===1) {
            return $this->getWidthSimple($unicode, $ftName);
        } elseif($this->getWidthType===2) {
            return $this->getWidthArray($unicode, $ftName);
        } else {
            throw new \Exception('font is undefined');
        }
    }

    /**
     * 取得目前設定的 [字型名稱 => 字型大小, ...] 的對應表
     * （單一字型也是以同樣格式回傳，只是只有一組 key=>value）
     * 
     * @return array [字型名稱 => 字型大小, ...] 的對應表
     */
    public function getSizeTable()
    {
        if(is_array($this->curFont)) {
            return $this->curFont;
        }
        return [$this->curFont => $this->ftSize];
    }

    /**
     * 依據目前所設定的字型，取得 ascent 與 descent
     * 如果是多個字型，則以最大值為回傳值
     * 
     * @return array ['ascent'=>基線以上空間, 'descent'=>基線以下空間]
     */
    public function getHeightInfo()
    {
        if($this->getWidthType===1) {
            $info=$this->fonts[$this->curFont]->getMtx();
            return [
                'ascent'=>$info['ascent']*$this->ftSize/1000,
                'descent'=>$info['descent']*$this->ftSize/1000
            ];
        } elseif($this->getWidthType===2) {
            $hMax=0;
            $dMax=0;
            $szMax=0;
            foreach($this->curFont as $ftName=>$ftSize) {
                $info=$this->fonts[$ftName]->getMtx();
                $h=$info['ascent'];
                $d=$info['descent'];
                if($hMax<$h){
                    $hMax=$h;
                }
                if($dMax>$d){                    
                    $dMax=$d;
                }
                if($szMax<$ftSize){
                    $szMax=$ftSize;
                }
            }
            return [
                'ascent'=>$hMax*$szMax/1000,
                'descent'=>$dMax*$szMax/1000
            ];
        } else {
            throw new \Exception('font is undefined');
        }
    }

    /**
     * 取得字型高度資訊
     * 
     * @return array {字形名稱:{size(pt),ascent(pt),descent(pt)}, ...}
     */
    public function getFontHeightInfo()
    {
        return $this->fontHeightInfo;
    }

    /**
     * 這個函式提供給 content module 的 write 方法中呼叫
     * 把 utf-16be 字串轉換為要寫在 content stream 中的資料
     * 
     * @param string $ftName PDF字型名稱
     * @param string $str 要輸出的字串(utf-16be)
     * @return string 16進位字串
     */
    public function getTextContent($ftName, $str)
    {
        return $this->fonts[$ftName]->getText($str);
    }

    //============ 以下函式由 pdfwriter 內部呼叫 ============

    /**
     * 讓目前 pdf 中使用的的字型都取 subset
     * 
     * @return void
     */
    public function preprocess()
    {
        foreach($this->fonts as $idx => $ft){
            $result=$ft->subset();
            if($result===false) {
                $this->fonts[$idx]=false;
            }
        }
        $this->fonts=array_filter($this->fonts);
    }

    /**
     * 寫入字型 dict 並回傳資源的 /Font 項目
     * 
     * @param object $writer StreamWriter 物件
     * @return string 資源的 /Font 項目
     */
    public function write(StreamWriter $writer)
    {
        $ids=[];
        foreach($this->fonts as $ftName => $fontObj) {
            $type=$fontObj->getType();
            if($type===0) {
                $id=$this->writeStandardFont($ftName, $writer);
            }elseif($type==1) {
                $id=$this->writeTrueType($ftName, $writer);
            }elseif($type==2) {
                $id=$this->writeOpenType($ftName, $writer);
            }
            $pdfFtName=$this->ft2name[$ftName];
            $ids[]="/$pdfFtName $id 0 R";
        }
        if(count($ids)===0) {
            return false;
        }
        $fts=implode(' ', $ids);
        return ['Font', $fts];
    }

    /**
     * 參考 getWidth 的說明
     */
    private function getWidthSimple($unicode, &$ftName)
    {
        $ftName=$this->curFont;
        $ft=$this->fonts[$this->curFont];
        $w=$ft->getWidth($unicode);
        if($w!==false) {
            return $w*$this->ftSize/1000;
        }
        return false;
    }

    /**
     * 參考 getWidth 的說明
     */
    private function getWidthArray($unicode, &$oFtName)
    {
        foreach($this->curFont as $ftName=>$ftSize) {
            $ft=$this->fonts[$ftName];
            $w=$ft->getWidth($unicode);
            $oFtName=$ftName;
            if($w!==false) {
                $w*=$ftSize/1000;
                break;
            }
        }
        return $w;
    }

    /**
     * 寫入 standard font
     * 
     * @param string $psName PDF字型名稱
     * @param object $writer StreamWriter 物件
     * @return int pdf 內使用的 obj id
     */
    private function writeStandardFont($psName, $writer)
    {
        $id=$writer->writeDict(implode("\n", [
            '/Type /Font',
            '/Subtype /Type1',
            '/BaseFont /'.$psName
        ]));
        return $id;
    }

    /**
     * 寫入 TrueType
     * 
     * @param string $ftName 字型名稱
     * @param object $writer StreamWriter 物件
     * @return int pdf 內使用的 obj id
     */
    private function writeTrueType($ftName, $writer)
    {
        $ftObj=$this->fonts[$ftName];
        $psName=$ftObj->getPostScriptName();
        $info=$ftObj->getInfo();
        $ftContent=$ftObj->getProgram();
        $len=$info['size'];
        $ttfStreamId=$writer->writeStream($ftContent, StreamWriter::FLATEDECODE, ['Length1'=>$len]);
        $subsetId=$this->subsetId++;
        $subsetFtName='AAAA'.chr(65+($subsetId-$subsetId%26)/26).chr(65+$subsetId%26).'+'.$psName;
        $descriptorId=$writer->writeDict(implode("\n", [
            '/Type /FontDescriptor',
            '/FontName /'.$subsetFtName,
            '/Flags 4',
            '/FontBBox ['.implode(' ', $info['bbox']).']',
            '/ItalicAngle '.$info['italicAngle'],
            '/Ascent '.$info['ascent'],
            '/Descent '.$info['descent'],
            '/CapHeight '.$info['capHeight'],
            '/StemV 0',
            '/FontFile2 '.$ttfStreamId.' 0 R'
        ]));
        $CIDFontId=$writer->writeDict(implode("\n", [
            '/Type /Font',
            '/Subtype /CIDFontType2',
            '/BaseFont /'.$subsetFtName,
            '/CIDSystemInfo <<',
            '/Registry (Adobe)',
            '/Ordering (Identity)',
            '/Supplement 0',
            '>>',
            '/FontDescriptor '.$descriptorId.' 0 R',
            '/W '.$this->converToW($ftName),
            '/CIDToGIDMap /Identity',
        ]));
        $toUincodeStr=$this->getToUnicode($ftName);
        $toUincodeId=$writer->writeStream($toUincodeStr, StreamWriter::COMPRESS);
        $fontId=$writer->writeDict(implode("\n", [
            '/Type /Font',
            '/Subtype /Type0',
            '/BaseFont /'.$subsetFtName,
            '/Encoding /Identity-H',
            '/DescendantFonts ['.$CIDFontId.' 0 R]',
            '/ToUnicode '.$toUincodeId.' 0 R'
        ]));
        return $fontId;
    }

    /**
     * 寫入 OpenType
     * 
     * @param string $ftName 字型名稱
     * @param object $writer StreamWriter 物件
     * @return int pdf 內使用的 obj id
     */
    private function writeOpenType($ftName, $writer)
    {
        $ftObj=$this->fonts[$ftName];
        $psName=$ftObj->getPostScriptName();
        $info=$ftObj->getInfo();
        $ftContent=$ftObj->getProgram();
        $len=$info['size'];
        $otfStreamId=$writer->writeStream(
            $ftContent, StreamWriter::FLATEDECODE, [
                'Subtype'=>'/CIDFontType0C'
            ]
        );
        $subsetId=$this->subsetId++;
        $subsetFtName='AAAA'.chr(65+($subsetId-$subsetId%26)/26).chr(65+$subsetId%26).'+'.$psName;
        $descriptorId=$writer->writeDict(implode("\n", [
            '/Type /FontDescriptor',
            '/FontName /'.$subsetFtName,
            '/Flags 4',
            '/FontBBox ['.implode(' ', $info['bbox']).']',
            '/ItalicAngle '.$info['italicAngle'],
            '/Ascent '.$info['ascent'],
            '/Descent '.$info['descent'],
            '/CapHeight '.$info['capHeight'],
            '/StemV 0',
            '/FontFile3 '.$otfStreamId.' 0 R'
        ]));
        $CIDFontId=$writer->writeDict(implode("\n", [
            '/Type /Font',
            '/Subtype /CIDFontType0',
            '/BaseFont /'.$subsetFtName,
            '/CIDSystemInfo <<',
            '/Registry (Adobe)',
            '/Ordering (Identity)',
            '/Supplement 0',
            '>>',
            '/FontDescriptor '.$descriptorId.' 0 R',
            '/W '.$this->converToW($ftName),
        ]));
        $toUincodeStr=$this->getToUnicode($ftName);
        $toUincodeId=$writer->writeStream($toUincodeStr, StreamWriter::COMPRESS);
        $fontId=$writer->writeDict(implode("\n", [
            '/Type /Font',
            '/Subtype /Type0',
            '/BaseFont /'.$subsetFtName,
            '/Encoding /Identity-H',
            '/DescendantFonts ['.$CIDFontId.' 0 R]',
            '/ToUnicode '.$toUincodeId.' 0 R'
        ]));
        return $fontId;
    }

    /**
     * 取得 /W 項目應該輸出的內容
     * 
     * @param string $ftName 字型名稱
     * @return string /W 項目應該輸出的內容
     */
    private function converToW($ftName)
    {
        $utw=$this->fonts[$ftName]->getW();
        $arr=array_keys($utw);
        sort($arr, SORT_NUMERIC);
        $tmp=[];
        $n=count($arr);
        if($n===0) {
            return '';
        }
        $si=0;
        $cW=$utw[$cU=$arr[$si]];
        for($i=1;$i<$n;) {
            for(;$i<$n;++$i){
                $w=$utw[$u=$arr[$i]];
                if($w!==$cW) {
                    break;
                }
            }
            $e=$i<$n?$u-1:$u;
            $tmp[]="$cU $e $cW";
            $si=$i;
            $cW=$w;
            $cU=$u;
        }
        return '['.implode(' ', $tmp).']';
    }

    /**
     * 取得 /ToUnicode 項目應該輸出的內容
     * 
     * @param string $ftName 字型名稱
     * @return string /ToUnicode 項目應該輸出的內容
     */
    private function getToUnicode($ftName)
    {
        $ctu=$this->fonts[$ftName]->getCTU();
        $lastIdx=count($ctu);
        if($lastIdx===0) {
            return false;
        }
        //
        $s=['0000'];
        foreach($ctu as $c=>$u) {
            if($u>0xffff) {
                $u-=0x10000;
                $s[]=str_pad(dechex(($u>>10)+0xD800), 4, '0', STR_PAD_LEFT).
                    str_pad(dechex(($u&0x3ff)+0xDC00), 4, '0', STR_PAD_LEFT);
            } else {
                $s[]=str_pad(dechex($u), 4, '0', STR_PAD_LEFT);
            }
        }
        $tmp=[];
        for($i=0;$i<$lastIdx+1;$i+=256) {
            $ss=array_slice($s, $i, 256);
            $end=count($ss);
            $tmp[]='<'.str_pad(dechex($i), 4, '0', STR_PAD_LEFT).'> <'.
                str_pad(dechex($i+$end-1), 4, '0', STR_PAD_LEFT).'> [<'.
                implode('> <', $ss).'>]';
        }
        $s=[
            '/CIDInit /ProcSet findresource begin',
            '12 dict begin',
            'begincmap',
            '/CIDSystemInfo <<',
            '  /Registry (Adobe)',
            '  /Ordering (UCS)',
            ' /Supplement 0',
            '>> def',
            '/CMapName /Adobe-Identity-UCS def',
            '/CMapType 2 def',
            '1 begincodespacerange',
            '<0000><ffff>',
            'endcodespacerange',
            count($tmp).' beginbfrange',
            implode("\n", $tmp),
            'endbfrange',
            'endcmap',
            'CMapName currentdict /CMap defineresource pop',
            'end',
            'end',
        ];
        return implode("\n", $s);
    }
}
