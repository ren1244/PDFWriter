<?php
namespace ren1244\PDFWriter\FontLib;

use ren1244\PDFWriter\Config;

class OpenType implements Font
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
        foreach(['unit', 'gtw', 'gtb', 'utg', 'mtx', 'tbPos', 'cff', 'psname'] as $k) {
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
        //更新 utg
        $data=Config::FONT_DIR.'/'.$this->ftName.'.bin';
        $data=file_get_contents($data);
        $tbPos=$this->tbPos;
        $usedUnicode=array_keys($this->usedUnicode);
        sort($usedUnicode, SORT_NUMERIC);
        $oldUTG=$this->utg;
        $newUTG=[];
        $nGid=1;
        $gidMap=[0=>0]; //新 gid -> 舊 gid
        foreach($usedUnicode as $u) {
            $gid=$oldUTG[$u];
            $gidMap[$nGid]=$gid;
            $newUTG[$u]=$nGid++;
        }
        $topDict=$this->cff['topDict'];
        $topDict['charset']=0;
        if(isset($topDict['Encoding'])) {
            unset($topDict['Encoding']);
        }
        $topPrsv=['CharStrings', 'charset', 'Private', 'FDArray', 'FDSelect', 'CIDCount'];
        $pos=$tbPos['headAndName']['len']+
            CFFLib::calIndexLen([CFFLib::calDictLen($topDict, CFFLib::TOP_DICT_OPERATORS, $topPrsv)])+
            $tbPos['stringAndGSubr']['len'];
        $optData=[]; //儲存 gSubr 之後的資料
        if(isset($topDict['Private'])) {
            $topDict['Private'][1]=$pos;
            $optData[]=substr($data, $tbPos['private']['pos'], $tbPos['private']['len']);
            $pos+=$tbPos['private']['len'];
        }
        if(isset($topDict['FDArray'])) {
            $topDict['FDArray']=$pos;
            $fdArray=&$this->cff['fdArray'];
            $tmp=[];
            $fdPrsv=['Private'];
            $n=count($fdArray);
            foreach($fdArray as $fdDict) {
                $tmp[]=CFFLib::calDictLen($fdDict, CFFLib::TOP_DICT_OPERATORS, $fdPrsv);
            }
            $pos+=CFFLib::calIndexLen($tmp);
            $tmp=[];
            for($i=0;$i<$n;++$i){
                if(isset($fdArray[$i]['Private'])) {
                    $fdArray[$i]['Private'][1]=$pos;
                    $pos+=$tbPos['FDPriv-'.$i]['len'];
                }
                $tmp[]=CFFLib::packDict($fdArray[$i], CFFLib::TOP_DICT_OPERATORS, $fdPrsv);
            }
            $optData[]=CFFLib::packIndex($tmp);
            for($i=0;$i<$n;++$i){
                if(isset($fdArray[$i]['Private'])) {
                    $key='FDPriv-'.$i;
                    $optData[]=substr($data, $tbPos[$key]['pos'], $tbPos[$key]['len']);
                }
            }
        }
        if(isset($topDict['FDSelect'])) {
            $topDict['FDSelect']=$pos;
            $tmp=chr(0); //use format 0
            $pFDSelect=$tbPos['fdSelect']['pos'];
            $tmp.=$data[$pFDSelect]; //gid 0
            foreach($usedUnicode as $u) {
                $gid=$oldUTG[$u];
                $tmp.=$data[$pFDSelect+$gid];
            }
            $optData[]=$tmp;
            $pos+=strlen($tmp);
        }
        if(isset($topDict['CIDCount'])) {
            $topDict['CIDCount']=$nGid;
        }
        $topDict['charset']=$pos;
        $optData[]=pack('Cn2', 2, 1, $nGid-2);
        $pos+=5;
        if(isset($topDict['CharStrings'])) {
            $topDict['CharStrings']=$pos;
            $startOffset=$tbPos['charStrings']['pos'];
            $loca=$this->cff['charStrings'];
            $cStrArr=[];

            $gid=0;
            $start=$loca[$gid]+$startOffset;
            $len=$loca[$gid+1]-$loca[$gid];
            $cStrArr[]=substr($data, $start, $len);
            foreach($usedUnicode as $u) {
                $gid=$oldUTG[$u]; //舊的 gid
                //抓 loca[gid-1] ~ loca[gid] 的資料
                $start=$loca[$gid]+$startOffset;
                $len=$loca[$gid+1]-$loca[$gid];
                $cStrArr[]=substr($data, $start, $len);
            }
        }
        $this->gidMap=$gidMap;
        $this->utg=$newUTG;
        $this->subsetFont=
            substr($data, $tbPos['headAndName']['pos'], $tbPos['headAndName']['len']).
            CFFLib::packIndex([CFFLib::packDict($topDict, CFFLib::TOP_DICT_OPERATORS, $topPrsv)]).
            substr($data, $tbPos['stringAndGSubr']['pos'], $tbPos['stringAndGSubr']['len']).
            implode('', $optData).
            CFFLib::packIndex($cStrArr);
        $this->subsetSize=strlen($this->subsetFont);
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
        $gidMap=$this->gidMap;
        $ctw=[];
        $gtw=$this->gtw;
        $utg=$this->utg;
        $unit=$this->unit;
        //unicode -> newgid
        //unicode -> newgid -> oldgid -> width
        foreach($this->usedUnicode as $u=>$x) {
            $gid=$utg[$u];
            $w=$gtw[$gidMap[$gid]];
            $ctw[$gid]=$w*1000/$unit;
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
        $arr['size']=false;
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
}