<?php
namespace ren1244\PDFWriter\FontLib;

use ren1244\PDFWriter\Config;

class TrueType implements Font
{
    //基本資訊
    private $psName;
    private $usedUnicode=[];
    private $unit;
    private $utg;
    private $utw;
    private $mtx;
    private $tbPos;
    //subset過程使用
    private $utb;
    private $loca; // gid => offset
    private $subsetFont;
    private $subsetSize;
    
    /**
     * 依據 $psName 初始化
     * 
     * @param string $psName 字型辨識名稱
     */
    public function __construct($psName)
    {
        $this->psName=$psName;
        $jsonFile=Config::FONT_DIR.'/'.$psName.'.json';
        if(!file_exists($jsonFile)) {
            throw new \Exception("TrueType font $psName not exsits");
        }
        $info=json_decode(file_get_contents($jsonFile), true);
        foreach(['unit', 'utw', 'utb', 'utg', 'mtx', 'tbPos', 'loca'] as $k) {
            $this->$k=$info[$k];
        }
        $scale=1000/$this->unit;
        foreach(['italicAngle', 'ascent', 'descent', 'capHeight'] as $k) {
            $this->mtx[$k]*=1000/$this->unit;
        }
        foreach($this->mtx['bbox'] as $i=>$v) {
            $this->mtx['bbox'][$i]*=1000/$this->unit;
        }
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
        $w=$this->utw[$unicode]??false;
        if($w===false || $w===0) {
            return false;
        }
        $this->usedUnicode[$unicode]=true;
        return $w*1000/$this->unit;
    }

    /**
     * 回傳字型資訊，包含：
     * bbox: 陣列 [xMin, yMin, xMax, yMax]
     * italicAngle: 斜體角度
     * ascent: 基線以上高度
     * descent: 基線以下高度
     * capHeight: capHeight（稍微小於或等於 ascent）
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
     * @return int 0 for Standard, 1 for TrueType
     */
    public function getType()
    {
        return 1;
    }

    /**
     * 依據曾經 getWidth 的 unicodes，建立 subset
     */
    public function subset()
    {
        $data=Config::FONT_DIR.'/'.$this->psName.'.bin';
        $data=file_get_contents($data);
        $glyfOffset=$this->tbPos['glyf']['pos'];
        //[Head, Hhea, Maxp, post, name, glyf]
        $gidMap=[0=>0]; //old gid => new gid
        $nGid=1; //目前使用到的 Gid 數量
        $loca=[0,0]; //新的 loca
        $locaLen=0; //loca offset 累加
        $glyf=[]; //新的 glyf
        $utg=[]; //新的 utg

        //$oldGlyf=$this->glyf;
        $oldLoca=$this->loca;
        $oldUTG=$this->utg;
        foreach($this->usedUnicode as $u=>$t) {
            $gid=$oldUTG[$u];
            if(isset($gidMap[$gid])) {
                //已經有的話就不用再輸出（當Composite Glyph出現時可能發生）
                continue;
            }
            $offset=$oldLoca[$gid];
            $length=$oldLoca[$gid+1]-$oldLoca[$gid];
            $numberOfContours=unpack('n', $data, $glyfOffset+$offset)[1];
            if($numberOfContours>>15&1){ //Composite Glyph
                $numberOfContours=-(~$numberOfContours&0xffff)-1;
                throw new \Exception('Composite Glyph Not Ready');
            } else { //Simple Glyph 
                $glyf[]=substr($data, $glyfOffset+$offset, $length);
                $locaLen+=$length;
                $loca[]=$locaLen;
                $utg[$u]=$nGid;
                $gidMap[$gid]=$nGid++;
            }
        }
        $out=[];
        $out['glyf']=implode('', $glyf);
        $out['loca']=pack('N*', ...$loca);
        $out['cmap']=$this->getCmap($utg);
        $out['hmtx']=$this->getHmtx($utg, $nGid);
        //head 54::50[n;1]2
        $pos=$this->tbPos['head']['pos'];
        $len=$this->tbPos['head']['len'];
        $out['head']=substr($data, $pos, 50).pack('n', 1).substr($data, $pos+52, 2);
        //Hhea 36::34[n;nGid]
        $pos=$this->tbPos['hhea']['pos'];
        $len=$this->tbPos['hhea']['len'];
        $out['hhea']=substr($data, $pos, 34).pack('n', $nGid);
        //Maxp 6+::4[n;nGid]
        $pos=$this->tbPos['maxp']['pos'];
        $len=$this->tbPos['maxp']['len'];
        $out['maxp']=substr($data, $pos, 4).pack('n', $nGid).substr($data, $pos+6, $len-6);
        //post & name
        foreach(['post', 'name'] as $k) {
            $out[$k]=substr($data, $this->tbPos[$k]['pos'], $this->tbPos[$k]['len']);
        }
        //產生表頭
        $tbs=['cmap', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp', 'name', 'post'];
        $s=[pack('Nn4', 0x00010000, 9, 48, 3, 9*16-48)];
        $offset=12+9*16;
        foreach($tbs as $tbName) {
            $len=strlen($out[$tbName]);
            if($len&3) {
                $out[$tbName].=str_repeat(chr(0), 4-($len&3));
                $len+=4-($len&3);
            }
            $s[]=$tbName.pack('N3', 0, $offset, $len);
            $offset+=$len;
        }
        foreach($tbs as $tbName) {
            $s[]=$out[$tbName];
        }
        $this->utg=$utg;
        $this->subsetFont=implode('', $s);
        $this->subsetSize=strlen($this->subsetFont);
    }

    //========以下為 subset 後會被呼叫========

    /**
     * 回傳字型資料(用 gzcompress 壓縮後的)
     * 
     * @return string 字型資料
     */
    public function getProgram()
    {
        return gzcompress($this->subsetFont, Config::GZIP_LEVEL);
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
        $ctw=[];
        $w=$this->utw;
        $utc=$this->utg;
        $unit=$this->unit;
        foreach($this->usedUnicode as $u=>$x) {
            $ctw[$utc[$u]]=$w[$u]*1000/$unit;
        }
        return $ctw;
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
        $arr=$this->mtx;
        $arr['size']=$this->subsetSize;
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
        $arr=array_values(unpack('n*', $str));
        $n=count($arr);
        $s='';
        $ctg=$this->utg;
        for($i=0;$i<$n;++$i) {
            $c=$arr[$i];
            if(0xd800<=$c && $c<=0xdbff) {
                $t=$arr[++$i];
                $c=($c-0xd800<<10|$t-0xdc00)+0x10000;
            }
            $c=$ctg[$c]??0;
            $s.=str_pad(dechex($c), 4, '0', STR_PAD_LEFT);
        }
        return $s;
    }

    /**
     * 從 uincode => gid 表建立 cmap 資料
     * 
     * @param array $utg unicode => gid 表
     * @return string cmap 表
     */
    private function getCmap($utg)
    {
        $groups=[];
        $arr=array_keys($utg);
        $n=count($arr);
        $startUincode=$endUnicode=$arr[0];
        $startGid=$utg[$startUincode];        
        for($i=1;$i<$n;++$i) {
            $u=$arr[$i];
            $g=$utg[$u];
            if($u-$startUincode!==$g-$startGid) {
                $groups[]=pack('N3', $startUincode, $endUnicode, $startGid);
                $startUincode=$endUnicode=$u;
                $startGid=$g;
            } else {
                $endUnicode=$u;
            }
        }
        $groups[]=pack('N3', $startUincode, $endUnicode, $startGid);
        $numGroups=count($groups);
        $length=16+$numGroups*12;
        return pack('n4N', 0, 1, 3, 10, 12).
            pack('n2N3', 12, 0, $length, 0, $numGroups).
            implode($groups);
    }

    /**
     * 建立 hmtx 表
     * 
     * @param array $utg unicode => gid 表
     * @param int $nGid 共有幾個 gid
     * @return string hmtx 表
     */
    private function getHmtx($utg, $nGid)
    {
        $gtu=array_flip($utg); //new gid=>unicode
        $utw=$this->utw;
        $utb=$this->utb;
        $out=[pack('n2', 1000, 0)];
        for($gid=1;$gid<$nGid;++$gid) {
            $u=$gtu[$gid]??0;
            $out[]=pack('n2', $utw[$u], $utb[$u]);
        }
        return implode('', $out);
    }
}