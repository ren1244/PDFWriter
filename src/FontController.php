<?php
namespace ren1244\PDFWriter;

/**
 * 字型管理器
 */
class FontController
{
    private $fonts=[];
    private $fontCount=0;
    private $curFont=false; //psName
    private $curName=false; //pdf font name
    private $ps2name=[];
    private $getWidthType=false;
    private $ftSize=12;
    private $subsetId=0;

    private static $standardFontName=[
        'Times-Roman', 'Times-Bold', 'Times-Italic', 'Times-BoldItalic',
        'Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique',
        'Courier', 'Courier-Bold', 'Courier-Oblique', 'Courier-BoldOblique',
        'Symbol'
    ];

    public function addFont($psName)
    {
        if(!isset($this->fonts[$psName])) {
            if(in_array($psName, self::$standardFontName)) {
                $ft=new \ren1244\PDFWriter\FontLib\StandardFont($psName);
            } else {
                $ft=new \ren1244\PDFWriter\FontLib\TrueType($psName);
            }
            $this->fonts[$psName]=$ft;
            $nameId=++$this->fontCount;
            $this->ps2name[$psName]="FT$nameId";
            $this->setFont($psName);
        }
    }

    public function getFontName($psName)
    {
        return $this->ps2name[$psName]??false;
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
            foreach($this->curFont as $psName=>$ftSize) {
                $info=$this->fonts[$psName]->getMtx();
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

    public function getText($psName, $str)
    {
        return $this->fonts[$psName]->getText($str);
    }

    public function subset()
    {
        foreach($this->fonts as $ft){
            $ft->subset();
        }
        //not ready
    }

    public function getTextContent($psName, $str)
    {
        return $this->fonts[$psName]->getText($str);
    }

    /**
     * 寫入 dict 並回傳 id 陣列
     */
    public function write($writer)
    {
        $ids=[];
        foreach($this->fonts as $psName => $fontObj) {
            $type=$fontObj->getType();
            if($type===0) {
                $id=$this->writeStandardFont($psName, $writer);
            }elseif($type==1) {
                $id=$this->writeType0($psName, $writer);
            }
            $ftName=$this->ps2name[$psName];
            $ids[]="/$ftName $id 0 R";
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

    private function getWidthArray($unicode, &$ftName)
    {
        foreach($this->curFont as $psName=>$ftSize) {
            $ft=$this->fonts[$psName];
            $w=$ft->getWidth($unicode);
            $ftName=$psName;
            if($w!==false) {
                $w*=$ftSize/1000;
                break;
            }
        }
        return $w;
    }

    private function writeStandardFont($ftname, $writer)
    {
        $id=$writer->writeDict(implode("\n", [
            '/Type /Font',
            '/Subtype /Type1',
            '/BaseFont /'.$ftname
        ]));
        return $id;
    }

    private function writeType0($ftname, $writer)
    {
        $ftObj=$this->fonts[$ftname];
        $info=$ftObj->getInfo();
        $ftContent=$ftObj->getProgram();
        $len=$info['size'];
        $ttfStreamId=$writer->writeStream($ftContent, StreamWriter::FLATEDECODE, ['Length1'=>$len]);
        $subsetId=$this->subsetId++;
        $subsetFtName='AAAA'.chr(65+($subsetId-$subsetId%26)/26).chr(65+$subsetId%26).'+'.$ftname;
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
            '/W '.$this->converToW($ftname),
            '/CIDToGIDMap /Identity',
        ]));
        $toUincodeStr=$this->getToUnicode($ftname);
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

    //public function converToW($ftName)
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
        $s=[];
        foreach($ctu as $c=>$u) {
            if($u>0xffff) {
                $u-=0x10000;
                $s[]=str_pad(dechex(($u>>10)+0xD800), 4, '0', STR_PAD_LEFT).
                    str_pad(dechex(($u&0x3ff)+0xDC00), 4, '0', STR_PAD_LEFT);
            } else {
                $s[]=str_pad(dechex($u), 4, '0', STR_PAD_LEFT);
            }
        }
        $s='<0001> <'.str_pad(dechex($lastIdx), 4, '0', STR_PAD_LEFT).
            '> [<'.implode('> <', $s).'>]';
        $s=[
            '/CIDInit /ProcSet findresource begin',
            '12 dict begin',
            'begincmap',
            '/CIDSystemInfo',
            '<< /Registry (Adobe)',
            '/Ordering (UCS)',
            '/Supplement 0',
            '>> def',
            '/CMapName /Adobe−Identity−UCS def',
            '/CMapType 2 def',
            '1 begincodespacerange',
            '<0000> <FFFF>',
            'endcodespacerange',
            '1 beginbfrange',
            $s,
            'endbfrange',
            '0 beginbfchar',
            'endbfchar',
            'endcmap',
            'CMapName currentdict /CMap defineresource pop',
            'end',
            'end',
        ];
        return implode("\n", $s);
        /*$fp=fopen(__DIR__.'/../log.txt', 'w');
        fwrite($fp, implode("\n", $s));
        fclose($fp);*/
    }
}