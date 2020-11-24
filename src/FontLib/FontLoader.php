<?php
namespace ren1244\PDFWriter\FontLib;

use ren1244\PDFWriter\FontLib\CmapEncoding;
use ren1244\PDFWriter\Config;

class FontLoader
{
    private $data;
    private $tbPos=[];
    private $maxGid=0;
    private $utg; //unicode to glygh id map
    private $widths=[];
    private $bearings=[];
    private $mtx=[];
    private $indexToLocFormat;
    private $names;
    private $loca=[];

    public function loadFile($filename, $outputNmae=false)
    {
        $this->data=file_get_contents($filename);
        $this->getTables();
        $this->readCmap();
        if($this->type==='TTF') {
            $this->readLoca();
        } else {
            $this->loca=[]; //目前無
            //$this->readCFF();
        }
        $this->readHhea();
        $this->readHead();
        $this->readPost();
        $this->getCapHeight();
        $this->readName();
        $max=count($this->widths)-1;
        $dw=$this->widths[$max];
        $scale=1000/$this->mtx['unitsPerEm'];
        $scale=1;
        if(!isset($this->names[6])) {
            throw new \Exception('no post script name');
        }
        $psname=$this->names[6];
        
        if($this->type==='TTF') {
            $tbNames=['head', 'hhea', 'maxp', 'post', 'name', 'glyf']; //會被另外保存的
            foreach(['cvt ', 'fpgm', 'prep'] as $optName) {
                if(isset($this->tbPos[$optName])) {
                    $tbNames[]=$optName;
                }
            }
            $newTbPos=[];
            $newTbPosSum=0;
            $newData=[];
            foreach($tbNames as $tbName){
                $len=$this->tbPos[$tbName]['length'];
                $pos=$this->tbPos[$tbName]['offset'];
                $newData[]=substr($this->data, $pos, $len);
                $newTbPos[$tbName]=[
                    'pos'=>$newTbPosSum,
                    'len'=>$len
                ];
                $newTbPosSum+=$len;
            }
            $newJson=false;
        } else {
            list($newJson, $newTbPos, $newData)=$this->getCFFData();
        }
        
        $output=[
            'type'=>$this->type,
            'psname'=>$psname,
            'unit'=>$this->mtx['unitsPerEm'],
            'gtw'=>$this->widths,
            'gtb'=>$this->bearings,
            'utg'=>$this->utg,
            'mtx'=>[
                'bbox'=>[
                    $this->mtx['bbox'][0]*$scale,
                    $this->mtx['bbox'][1]*$scale,
                    $this->mtx['bbox'][2]*$scale,
                    $this->mtx['bbox'][3]*$scale
                ],
                'italicAngle'=>$this->mtx['italicAngle'],
                'ascent'=>$this->mtx['ascender']*$scale,
                'descent'=>$this->mtx['descender']*$scale,
                'capHeight'=>$this->mtx['capHeight']*$scale,
            ],
            'loca'=>$this->loca,
            'tbPos'=>$newTbPos,
        ];
        if($newJson) {
            $output['cff']=$newJson;
        }
        if($outputNmae===false) {
            $outputNmae=$psname;
        }
        file_put_contents(Config::FONT_DIR.'/'.$outputNmae.'.json', json_encode($output,  JSON_UNESCAPED_UNICODE| JSON_UNESCAPED_SLASHES| JSON_PRETTY_PRINT));        
        if($this->type==='TTF') {
            file_put_contents(Config::FONT_DIR.'/'.$outputNmae.'.bin', implode('', $newData));
        } else {
            file_put_contents(Config::FONT_DIR.'/'.$outputNmae.'.bin', implode('', $newData));
        }
    }

    private function getTables()
    {
        $arr=unpack('Nver/nnTables', $this->data);
        if($arr['ver']!==0x00010000 && $arr['ver']!==0x4F54544F) {
            throw new \Exception('bad fomat');
        }
        $this->type=$arr['ver']===0x00010000?'TTF':'OTF';
        $pos=12;
        $nTable=$arr['nTables'];
        for($i=0;$i<$nTable;++$i) {
            $tag=substr($this->data, $pos, 4);
            $this->tbPos[$tag]=unpack('Noffset/Nlength', $this->data, $pos+8);
            $pos+=16;
        }
    }

    private function readCmap()
    {
        $data=$this->data;
        $start=$pos=$this->tbPos['cmap']['offset'];
        $arr=unpack('nver/nnTables', $data, $pos);
        $nTables=$arr['nTables'];
        $pos+=4;
        $usePlatEnc=['3-10', '3-1'];
        $oid=false;
        $m=[];
        for($i=0;$i<$nTables;++$i) {
            $arr=unpack('npid/neid/Noffset', $data, $pos);
            $pos+=8;
            $key="${arr['pid']}-${arr['eid']}";
            if(in_array($key, $usePlatEnc)) {
                $offset=$arr['offset']+$start;
                //解析這個 encoding
                $fmt=unpack('nfmt', $data, $offset)['fmt'];
                $result=call_user_func([__NAMESPACE__.'\CmapEncoding',"fmt$fmt"], $data, $offset);
                foreach($result as $key=>$val) {
                    if(!isset($m[$key])) {
                        $m[$key]=$val;
                        if($this->maxGid<$val) {
                            $this->maxGid=$val;
                        }
                    } elseif($m[$key]!==$val){
                        throw new \Exception('diff');
                    }
                }
            }
        }
        $this->utg=$m;
    }

    private function readHhea()
    {
        $data=$this->data;
        $pos=$this->tbPos['maxp']['offset'];
        $maxGid=unpack('n', $data, $pos+4)[1];
        $pos=$this->tbPos['hhea']['offset'];
        $arr=unpack('nascender/ndescender/nlineGap', $data, $pos+4);
        foreach($arr as $key=>$val) {
            if($val>>15&1) {
                $val=-((~$val&0xffff)+1);
            }
            $this->mtx[$key]=$val;
        }
        $numberOfHMetrics=unpack('n', $data, $pos+34)[1];
        $pos=$this->tbPos['hmtx']['offset'];
        $endPos=$this->tbPos['hmtx']['length']+$pos;
        $widths=[];
        $bearings=[];
        for($i=0;$i<$numberOfHMetrics && $pos+3<$endPos;++$i) {
            $advanceWidth=(ord($data[$pos])<<8|ord($data[1+$pos]));
            $lsb=(ord($data[2+$pos])<<8|ord($data[3+$pos]));
            $pos+=4;
            $widths[]=$advanceWidth;
            $bearings[]=$lsb;
        }
        for(;$i<=$maxGid;++$i){
            if($pos+1<$endPos) {
                $lsb=(ord($data[$pos])<<8|ord($data[1+$pos]));
            }
            $pos+=2;
            $widths[]=$advanceWidth;
            $bearings[]=$lsb;
        }
        $this->widths=$widths;
        $this->bearings=$bearings;
    }

    private function readHead()
    {
        $data=$this->data;
        $pos=$this->tbPos['head']['offset'];
        $unitsPerEm=unpack('n', $data, $pos+18)[1];
        $arr=unpack('nxMin/nyMin/nxMax/nyMax', $data, $pos+36);
        $this->indexToLocFormat=unpack('n', $data, $pos+50)[1];
        $bbox=[];
        foreach($arr as $k=>$v) {
            if($v>>15&1) {
                $v=-((~$v&0xffff)+1);
            }
            $bbox[]=$v;
        }
        $this->mtx['unitsPerEm']=$unitsPerEm;
        $this->mtx['bbox']=$bbox;
    }

    private function readPost()
    {
        $data=$this->data;
        $pos=$this->tbPos['post']['offset'];
        $arr=unpack('NitalicAngle/nunderlinePosition/nunderlineThickness', $data, $pos+4);
        foreach(['underlinePosition', 'underlineThickness'] as $k) {
            $v=$arr[$k];
            if($v>>15&1) {
                $v=-((~$v&0xffff)+1);
            }
            $this->mtx[$k]=$v;
        }
        $this->mtx['italicAngle']=$arr['italicAngle'];
    }

    private function getCapHeight()
    {
        $data=$this->data;
        if(isset($this->tbPos['OS/2'])) {
            $pos=$this->tbPos['OS/2']['offset'];
            $ver=unpack('n', $data, $pos)[1];
            if($ver>1) {
                $h=unpack('n', $data, $pos+88)[1];
                if($h>>15&1) {
                    $h=-((~$h&0xffff)+1);
                }
                $this->mtx['capHeight']=$h;
                return;
            }
        } else {
            $this->mtx['capHeight']=$this->mtx['ascender'];
        }
    }

    /**
     * 讀取 loca 資料到 loca
     */
    private function readLoca()
    {
        //先讀取 indexToLocFormat
        $data=$this->data;
        $pos=$this->tbPos['head']['offset'];
        $fmt=$indexToLocFormat=unpack('n', $data, $pos+50)[1];
        //依據各文字紀錄位置
        $pos=$this->tbPos['loca']['offset'];
        $n=$this->maxGid+2;
        if($fmt) { //indexToLocFormat === 1
            $this->loca=array_values(unpack('N'.$n, $data, $pos));
        } else { //indexToLocFormat === 0
            $this->loca=array_map(function($x){
                return $x*2;
            },array_values(unpack('n'.$n, $data, $pos)));
        }
    }

    private function readName()
    {
        $data=$this->data;
        $pos=$this->tbPos['name']['offset'];
        $arr=unpack('nfmt/ncount/nstrOffset', $data, $pos);
        foreach($arr as $k=>$v){
            $$k=$v;
        }
        $storageOffset=$pos+$count*12+6;
        if($fmt===1) {
            $langTagCount=unpack('n', $data, $storageOffset)[1];
            $storageOffset+=2+$langTagCount*4;
        }
        $result=[];
        for($i=0; $i<$count; ++$i) {
            $arr=unpack('nplatId/nencId/nlangId/nnameId/nlength/noffset', $data, $i*12+$pos+6);
            if(
                !isset($result[$arr['nameId']]) &&
                $arr['platId']===3 && 
                ($arr['encId']===1||$arr['encId']===10)
            ) {
                
                $result[$arr['nameId']]=mb_convert_encoding(
                    substr($data, $storageOffset+$arr['offset'], $arr['length']),
                    'UTF-8',
                    'UTF-16BE'
                );
            }
        }
        $this->names=$result;
    }

    private function getCFFData()
    {
        $data=$this->data;
        $cffOffset=$this->tbPos['CFF ']['offset'];
        $pos=$cffOffset; //舊資料讀取到哪邊
        $newData=[];
        $newPos=[];
        $newLen=0;
        $newJson=[];
        //Head And Name
        $namePos=CFFLib::unpackIndexPos($data, $pos+ord($data[$pos+2]));
        $tmpData=substr($data, $pos, $namePos['e']-$pos);
        $this->pushNewData('headAndName', $tmpData, $newPos, $newData, $newLen);
        $pos=$namePos['e'];
        //topDict 存 json
        $topDictPos=CFFLib::unpackIndexPos($data, $pos);
        $start=$topDictPos['arr'][0];
        $end=$topDictPos['arr'][1];
        $topDict=CFFLib::unpackDict($data, $start, $end-$start, CFFLib::TOP_DICT_OPERATORS);
        $newJson['topDict']=&$topDict;
        $pos=$topDictPos['e'];
        //strings and gSubr
        $stringPos=CFFLib::unpackIndexPos($data, $pos);
        $gSubrPos=CFFLib::unpackIndexPos($data, $stringPos['e']);
        $tmpData=substr($data, $pos, $gSubrPos['e']-$pos);
        $this->pushNewData('stringAndGSubr', $tmpData, $newPos, $newData, $newLen);
        $pos=$gSubrPos['e'];
        //CharStrings，index 資訊改儲存於 json
        $pos=$cffOffset+$topDict['CharStrings'];
        $charStringPos=CFFLib::unpackIndexPos($data, $pos);
        $start=$charStringPos['arr'][0];
        $end=$charStringPos['arr'][$charStringPos['c']];
        $tmpData=substr($data, $start, $end-$start);
        $this->pushNewData('charStrings', $tmpData, $newPos, $newData, $newLen);
        $newJson['charStrings']=$this->resetPos($charStringPos['arr']);
        //Private and psubr
        if(isset($topDict['Private'])) {
            $pos=$cffOffset+$topDict['Private'][1];
            $len=$topDict['Private'][0];
            $privateDict=CFFLib::unpackDict($data, $pos, $len, CFFLib::PRIVATE_DICT_OPERATORS);
            if(isset($privateDict['Subrs'])) {                
                $pos+=$privateDict['Subrs'];
                $pSubrPos=CFFLib::unpackIndexPos($data, $pos);
                $privLen=CFFLib::calDictLen($privateDict, CFFLib::PRIVATE_DICT_OPERATORS, ['Subrs']);
                $privateDict['Subrs']=$privLen; //把 subr 直接接在 private 後面
                $tmpData=
                    CFFLib::packDict($privateDict, CFFLib::PRIVATE_DICT_OPERATORS, ['Subrs']).
                    substr($data, $pSubrPos['s'], $pSubrPos['e']-$pSubrPos['s']);
            } else {
                $privLen=$topDict['Private'][0];
                $tmpData=substr($data, $cffOffset+$topDict['Private'][1], $privLen);
            }
            $topDict['Private']=[$privLen, 0];
            $this->pushNewData('private', $tmpData, $newPos, $newData, $newLen);
        }
        //FDArray
        if(isset($topDict['FDArray'])) {
            $pos=$cffOffset+$topDict['FDArray'];
            $fdArrPos=CFFLib::unpackIndexPos($data, $pos);
            $n=$fdArrPos['c'];
            $tmp=[];
            for($i=0;$i<$n;++$i) {
                $start=$fdArrPos['arr'][$i];
                $end=$fdArrPos['arr'][$i+1];
                $tmp[]=CFFLib::unpackDict($data, $start, $end-$start, CFFLib::TOP_DICT_OPERATORS);
                if(isset($tmp[$i]['Private'])) {
                    $pos=$cffOffset+$tmp[$i]['Private'][1];
                    $len=$tmp[$i]['Private'][0];
                    $privateDict=CFFLib::unpackDict($data, $pos, $len, CFFLib::PRIVATE_DICT_OPERATORS);
                    if(isset($privateDict['Subrs'])) {
                        $pos+=$privateDict['Subrs'];
                        $pSubrPos=CFFLib::unpackIndexPos($data, $pos);
                        $privLen=CFFLib::calDictLen($privateDict, CFFLib::PRIVATE_DICT_OPERATORS, ['Subrs']);
                        $privateDict['Subrs']=$privLen; //把 subr 直接接在 private 後面
                        $tmpData=
                            CFFLib::packDict($privateDict, CFFLib::PRIVATE_DICT_OPERATORS, ['Subrs']).
                            substr($data, $pSubrPos['s'], $pSubrPos['e']-$pSubrPos['s']);
                    } else {
                        $privLen=$tmp[$i]['Private'][0];
                        $tmpData=substr($data, $cffOffset+$tmp[$i]['Private'][1], $privLen);
                    }
                    $tmp[$i]['Private']=[$privLen, 0];
                    $this->pushNewData('FDPriv-'.$i, $tmpData, $newPos, $newData, $newLen);
                }
            }
            $newJson['fdArray']=$tmp;
        }
        //FDSelect
        $nGlyf=$charStringPos['c'];
        if(isset($topDict['FDSelect'])) {
            $pos=$cffOffset+$topDict['FDSelect'];
            $fmt=ord($data[$pos++]);
            $tmpData='';
            if($fmt===0) {
                $tmpData=substr($data, $pos, $nGlyf);
            } elseif($fmt===3) {
                $nRanges=ord($data[$pos])<<8|ord($data[$pos+1]);
                $pos+=2;
                $first=ord($data[$pos])<<8|ord($data[$pos+1]);
                $pos+=2;
                for($i=0;$i<$nRanges;++$i) {
                    $fd=$data[$pos++];
                    $nextFirst=ord($data[$pos])<<8|ord($data[$pos+1]);
                    $pos+=2;
                    $tmpData.=str_repeat($fd, $nextFirst-$first);
                    $first=$nextFirst;
                }
            } else {
                throw new \Exception('FDSelect: unknow format');
            }
            $this->pushNewData('fdSelect', $tmpData, $newPos, $newData, $newLen);
        }
        return [$newJson, $newPos, $newData];
    }

    private function pushNewData($key, $value, &$newPos, &$newData, &$newLen)
    {
        $len=strlen($value);
        $newData[]=$value;
        $newPos[$key]=[
            'pos' => $newLen,
            'len' => $len
        ];
        $newLen+=$len;
    }

    private function resetPos(&$arr)
    {
        $n=count($arr);
        $first=$arr[0];
        for($i=0;$i<$n;++$i){
            $arr[$i]-=$first;
        }
        return $arr;
    }
}
