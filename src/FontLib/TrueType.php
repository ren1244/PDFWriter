<?php
namespace ren1244\PDFWriter\FontLib;

use ren1244\PDFWriter\Config;

class TrueType implements Font
{
    //基本資訊
    private $ftName;
    private $usedUnicode=[];
    private $unit;
    private $utg;
    private $gtw;
    private $mtx;
    private $tbPos;
    //subset過程使用
    private $gidMap;
    private $gtb;
    private $loca; // gid => offset
    private $subsetFont;
    private $subsetSize;
    
    /**
     * 依據 $ftName 初始化
     * 
     * @param string $ftName 字型辨識名稱
     */
    public function __construct($ftName, $ftJson)
    {
        $this->ftName=$ftName;
        foreach(['unit', 'gtw', 'gtb', 'utg', 'mtx', 'tbPos', 'loca', 'psname'] as $k) {
            $this->$k=$ftJson[$k];
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
        $u=$this->utg[$unicode]??false;
        if($u===false || $u===0) {
            return false;
        }
        $this->usedUnicode[$unicode]=true;
        return $this->gtw[$u]*1000/$this->unit;
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
        $data=Config::FONT_DIR.'/custom/'.$this->ftName.'.bin';
        $data=file_get_contents($data);
        $glyfOffset=$this->tbPos['glyf']['pos'];
        $usedUnicode=array_keys($this->usedUnicode);
        if(empty($usedUnicode)) {
            return false;
        }
        sort($usedUnicode, SORT_NUMERIC);
        //[Head, Hhea, Maxp, post, name, glyf]
        $gidMap=[0=>0]; //old gid => new gid
        $nGid=1; //目前使用到的 Gid 數量
        $loca=[0,0]; //新的 loca
        $locaLen=0; //loca offset 累加
        $glyf=[]; //新的 glyf
        $utg=[]; //新的 utg(只存用到部分)
        //$utg2=[]; //新的 gtw(只存複合字型相依，但是不在 subset 中)

        //$oldGlyf=$this->glyf;
        $oldLoca=$this->loca;
        $oldUTG=$this->utg;
        $compositeQueue=[];
        $compositeCount=0;
        foreach($usedUnicode as $u) {
            $gid=$oldUTG[$u];
            if(isset($gidMap[$gid])) {
                //已經有的話就不用再輸出（當Composite Glyph出現時可能發生）
                continue;
            }
            $offset=$oldLoca[$gid];
            $length=$oldLoca[$gid+1]-$oldLoca[$gid];
            $numberOfContours=unpack('n', $data, $glyfOffset+$offset)[1];
            if($numberOfContours>>15&1){ //Composite Glyph
                //$compositeIdx 存 $glyf 的 index
                $compositeQueue[$compositeCount++]=$nGid-1;
                //$glyf 存 old gid
                $glyf[]=substr($data, $glyfOffset+$offset, $length);
                $locaLen+=$length;
                $loca[]=$locaLen;
                $utg[$u]=$nGid;
                $gidMap[$gid]=$nGid++;
            } else { //Simple Glyph 
                $glyf[]=substr($data, $glyfOffset+$offset, $length);
                $locaLen+=$length;
                $loca[]=$locaLen;
                $utg[$u]=$nGid;
                $gidMap[$gid]=$nGid++;
            }
        }
        $nGid2=$nGid;
        if($compositeCount>0) {
            $oldGTU=array_flip($this->utg);
        }
        for($i=0;$i<$compositeCount;++$i) {
            $glyfIdx=$compositeQueue[$i];
            $curGlyf=$glyf[$glyfIdx];
            $curPos=10;
            $curGlyfNew=[substr($curGlyf, 0, 10)];
            do {
                $curGid=unpack('n2', $curGlyf, $curPos);
                $flag=$curGid[1];
                $curGid=$curGid[2];
                $curLen=($flag&1?8:6)+($flag&0x08?2:($flag&0x40?4:($flag&0x80?8:0)));
                if(!isset($gidMap[$curGid])) {
                    $offset=$oldLoca[$curGid];
                    $length=$oldLoca[$curGid+1]-$oldLoca[$curGid];
                    $numberOfContours=unpack('n', $data, $glyfOffset+$offset)[1];
                    $curGlyfNew[]=pack('n2', $flag, $nGid2).substr($curGlyf, $curPos+4, $curLen-4);
                    if($numberOfContours>>15&1){ //Composite Glyph
                        $compositeQueue[$compositeCount++]=$nGid2-1;
                    }
                    $glyf[]=substr($data, $glyfOffset+$offset, $length);
                    $locaLen+=$length;
                    $loca[]=$locaLen;
                    $gidMap[$curGid]=$nGid2++;
                } else {
                    $curGlyfNew[]=pack('n2', $flag, $gidMap[$curGid]).substr($curGlyf, $curPos+4, $curLen-4);
                }
                //移到下一個位置
                $curPos+=$curLen;
            } while($flag&0x20); // MORE_COMPONENTS
            $curGlyfNew[]=substr($curGlyf, $curPos);
            $curGlyfNew=implode('', $curGlyfNew);
            $glyf[$glyfIdx]=$curGlyfNew;
        }
        $this->gidMap=array_flip($gidMap);
        $out=[];
        $out['glyf']=implode('', $glyf);
        $out['loca']=pack('N*', ...$loca);
        $out['cmap']=$this->getCmap($utg);
        $out['hmtx']=$this->getHmtx($nGid2);
        //head 54::50[n;1]2
        $pos=$this->tbPos['head']['pos'];
        $len=$this->tbPos['head']['len'];
        $out['head']=substr($data, $pos, 50).pack('n', 1).substr($data, $pos+52, 2);
        //Hhea 36::34[n;nGid]
        $pos=$this->tbPos['hhea']['pos'];
        $len=$this->tbPos['hhea']['len'];
        $out['hhea']=substr($data, $pos, 34).pack('n', $nGid2);
        //Maxp 6+::4[n;nGid]
        $pos=$this->tbPos['maxp']['pos'];
        $len=$this->tbPos['maxp']['len'];
        $out['maxp']=substr($data, $pos, 4).pack('n', $nGid2).substr($data, $pos+6, $len-6);
        //post & name
        $tbs=['cmap', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp', 'name', 'post'];
        $copyTables=['post', 'name'];
        foreach(['cvt ', 'fpgm', 'prep'] as $optName) {
            if(isset($this->tbPos[$optName])) {
                $tbs[]=$optName;
                $copyTables[]=$optName;
            }
        }
        foreach($copyTables as $k) {
            $out[$k]=substr($data, $this->tbPos[$k]['pos'], $this->tbPos[$k]['len']);
        }
        //產生表頭
        $tbCount=count($tbs);
        $logTbCount=floor(log($tbCount, 2));
        $powOf2=round(pow(2, $logTbCount));
        $s=[pack('Nn4', 0x00010000, $tbCount, $powOf2*16, $logTbCount, ($tbCount-$powOf2)*16)];
        $offset=12+$tbCount*16;
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
        return $this->psname;
    }

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
        $gtw=$this->gtw;
        $utg=$this->utg;
        $gidMap=$this->gidMap;
        $unit=$this->unit;
        //unicode -> newgid
        //unicode -> newgid -> oldgid -> width
        foreach($this->usedUnicode as $u=>$x) {
            $newGid=$utg[$u];
            $oldGid=$gidMap[$newGid];
            $w=$gtw[$oldGid];
            $ctw[$newGid]=$w*1000/$unit;
        }
        return $ctw;
    }

    /**
     * 取得使用到文字的 ctu 映射表
     * 這會用來產生 pdf Font 的 /ToUnicode entry
     * 
     * @return array 回傳 cid => unicode 映射表
     */
    public function getCTU()
    {
        return array_flip($this->utg);
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
     * @param int $nGid 共有幾個 gid
     * @return string hmtx 表
     */
    private function getHmtx($nGid)
    {
        $gidMap=$this->gidMap;
        $gtw=$this->gtw;
        $gtb=$this->gtb;
        $out=[pack('n2', 1000, 0)];
        for($gid=1;$gid<$nGid;++$gid) {
            $oldGid=$gidMap[$gid];
            $w=$gtw[$oldGid]??1000;
            $b=$gtb[$oldGid]??0;
            $out[]=pack('n2', $w, $b);
        }
        return implode('', $out);
    }
}