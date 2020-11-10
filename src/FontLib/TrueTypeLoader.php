<?php
namespace ren1244\PDFWriter\FontLib;

use ren1244\PDFWriter\FontLib\CmapEncoding;
use ren1244\PDFWriter\Config;

class TrueTypeLoader
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

    public function loadFile($filename)
    {
        $this->data=file_get_contents($filename);
        $this->getTables();
        $this->readCmap();
        $this->readLoca();
        $this->readHhea();
        $this->readHead();
        $this->readPost();
        $this->getCapHeight();
        $this->readName();
        $max=count($this->widths)-1;
        $dw=$this->widths[$max];
        $wArr=[];$bArr=[];
        $scale=1000/$this->mtx['unitsPerEm'];
        $scale=1;
        foreach($this->utg as $c=>$g) {
            $w=$g>$max?$dw:$this->widths[$g];
            $b=$g>$max?0:$this->bearings[$g];
            $wArr[$c]=$w*$scale;
            $bArr[$c]=$b*$scale;
        }
        if(!isset($this->names[6])) {
            throw new \Exception('no post script name');
        }
        $psname=$this->names[6];
        $tbNames=['head', 'hhea', 'maxp', 'post', 'name', 'glyf'];
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
        $output=[
            'unit'=>$this->mtx['unitsPerEm'],
            'utw'=>$wArr,
            'utb'=>$bArr,
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
        file_put_contents(Config::FONT_DIR.'/'.$psname.'.json', json_encode($output,  JSON_UNESCAPED_UNICODE| JSON_UNESCAPED_SLASHES| JSON_PRETTY_PRINT));        
        file_put_contents(Config::FONT_DIR.'/'.$psname.'.bin', implode('', $newData));
    }

    private function getTables()
    {
        $arr=unpack('Nver/nnTables', $this->data);
        if($arr['ver']!==0x00010000) {
            throw new \Exception('bad fomat');
        }
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
        $pos=$this->tbPos['hhea']['offset'];
        //var_dump($pos);
        $arr=unpack('nascender/ndescender/nlineGap', $data, $pos+4);
        foreach($arr as $key=>$val) {
            if($val>>15&1) {
                $val=-((~$val&0xffff)+1);
            }
            $this->mtx[$key]=$val;
        }
        $numberOfHMetrics=unpack('n', $data, $pos+34)[1];
        $pos=$this->tbPos['hmtx']['offset'];
        $widths=[];
        $bearings=[];
        for($i=0;$i<$numberOfHMetrics;++$i) {
            $advanceWidth=(ord($data[$pos])<<8|ord($data[1+$pos]));
            $lsb=(ord($data[2+$pos])<<8|ord($data[3+$pos]));
            $pos+=4;
            $widths[]=$advanceWidth;
            $bearings[]=$lsb;
        }
        $maxGid=$this->maxGid;
        for(;$i<=$maxGid;++$i){
            $lsb=(ord($data[$pos])<<8|ord($data[1+$pos]));
            $pos+=2;
            $widths[]=$advenceWidth;
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
        }
        $pos=$this->tbPos['loca']['offset'];
        $fmt=$this->indexToLocFormat;
        $gid=$this->utg[ord('H')]??false;
        if($gid===false) {
            throw new \Exception('no H in font');
        }
        if($fmt!==0 && $fmt!==1) {
            throw new \Exception('bad indexToLocFormat');
        }
        if($fmt) {
            $arr=unpack('N2', $data, $pos+$gid*4);
            $offset=$arr[1];
            $length=$arr[2]-$arr[1];
        } else {
            $arr=unpack('n2', $data, $pos+$gid*2);
            $offset=$arr[1]*2;
            $length=($arr[2]-$arr[1])*2;
        }
        $pos=$this->tbPos['glyf']['offset']+$offset;
        $arr=unpack('n4', $data, $pos+2);
        foreach($arr as $k=>$v) {
            if($v>>15&1) {
                $v=-((~$v&0xffff)+1);
            }
            $arr[$k]=$v;
        }
        $this->mtx['capHeight']=$arr[4]-$arr[2];
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
}
