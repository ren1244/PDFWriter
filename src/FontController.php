<?php
namespace ren1244\PDFWriter;

/**
 * 字型管理器
 */
class FontController
{
    private $fonts=[];
    private $fontCount=0;
    private $curFont=false; //ftName
    private $curName=false; //pdf font name
    private $ft2name=[]; //字型檔案的名稱 => PDF內字型名稱
    private $getWidthType=false;
    private $ftSize=12;
    private $subsetId=0;

    private static $standardFontName=[
        'Times-Roman', 'Times-Bold', 'Times-Italic', 'Times-BoldItalic',
        'Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique',
        'Courier', 'Courier-Bold', 'Courier-Oblique', 'Courier-BoldOblique',
        'Symbol'
    ];

    public function addFont($ftName)
    {
        if(!isset($this->fonts[$ftName])) {
            if(in_array($ftName, self::$standardFontName)) {
                $ft=new \ren1244\PDFWriter\FontLib\StandardFont($ftName);
            } else {
                $jsonFile=Config::FONT_DIR.'/'.$ftName.'.json';
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

    public function getFontName($ftName)
    {
        return $this->ft2name[$ftName]??false;
    }

    /**
     * 取得字的寬度
     * 
     * @param int $unicode unicode
     * @param mixed &$ftName 如果找到字，會被寫入
     * @return int 字的寬度
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

    public function setFont($font, $size=false)
    {
        if($size===false){
            $size=$this->ftSize;
        }
        if(is_array($font)) {
            $this->getWidthType=2;
            $this->curFont=$font;
        } else {
            $this->getWidthType=1;
            $this->curFont=$font;
            $this->ftSize=$size;
            $this->curName=$this->getFontName($font);
        }
    }

    public function getSizeTable()
    {
        if(is_array($this->curFont)) {
            return $this->curFont;
        }
        return [$this->curFont => $this->ftSize];
    }

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

    public function getText($ftName, $str)
    {
        return $this->fonts[$ftName]->getText($str);
    }

    public function subset()
    {
        foreach($this->fonts as $ft){
            $ft->subset();
        }
        //not ready
    }

    public function getTextContent($ftName, $str)
    {
        return $this->fonts[$ftName]->getText($str);
    }

    /**
     * 寫入 dict 並回傳 id 陣列
     */
    public function write($writer)
    {
        $ids=[];
        foreach($this->fonts as $ftName => $fontObj) {
            $type=$fontObj->getType();
            if($type===0) {
                $id=$this->writeStandardFont($ftName, $writer);
            }elseif($type==1) {
                $id=$this->writeType0($ftName, $writer);
            }elseif($type==2) {
                $id=$this->writeOpenType($ftName, $writer);
            }
            $pdfFtName=$this->ft2name[$ftName];
            $ids[]="/$pdfFtName $id 0 R";
        }
        if(count($ids)===0) {
            return '';
        }
        $fts=implode(' ', $ids);
        return "/Font << $fts >>";
    }

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

    private function writeStandardFont($psName, $writer)
    {
        $id=$writer->writeDict(implode("\n", [
            '/Type /Font',
            '/Subtype /Type1',
            '/BaseFont /'.$psName
        ]));
        return $id;
    }

    private function writeType0($ftName, $writer)
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
