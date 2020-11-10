<?php
namespace ren1244\PDFWriter\FontLib;

class CmapEncoding
{
    public static function fmt0($data, $offset)
    {
        $arr=unpack('C256', $data, $offset+6);
        return array_values($arr);
    }

    public static function fmt2($data, $offset)
    {
        //尚未完成
        //$arr=unpack($data, 'nlen/nlang/n256sub', $offset+2);
        throw new \Exception('cmap encoding fmt2 not ready');
        return [];
    }

    public static function fmt4($data, $offset)
    {
        $segx2=unpack('nsegx2', $data, $offset+6)['segx2'];
        $endCodePos=$offset+14;
        $startCodePos=$offset+16+$segx2;
        $idDeltaPos=$offset+16+$segx2*2;
        $idRangeOffsetPos=$offset+16+$segx2*3;
        $m=[];
        for($i=0;$i<$segx2;$i+=2) {
            $s=(ord($data[$i+$startCodePos])<<8|ord($data[$i+1+$startCodePos]));
            $e=(ord($data[$i+$endCodePos])<<8|ord($data[$i+1+$endCodePos]));
            $d=(ord($data[$i+$idDeltaPos])<<8|ord($data[$i+1+$idDeltaPos]));
            $o=(ord($data[$i+$idRangeOffsetPos])<<8|ord($data[$i+1+$idRangeOffsetPos]));
            if($o===0) {
                for($c=$s; $c<=$e; ++$c) {
                    $g=($c+$d&0xffff);
                    if($g!==0) {
                        $m[$c]=$g;
                    }
                }
            } else {
                for($c=$s; $c<=$e; ++$c) {
                    $g=$i+$o+($c-$s)*2;
                    $g=(ord($data[$g+$idRangeOffsetPos])<<8|ord($data[$g+1+$idRangeOffsetPos]));
                    if($g!==0) {
                        $m[$c]=$g;
                    }
                }
            }
        }
        return $m;
    }

    public static function fmt6($data, $offset)
    {
        throw new \Exception('cmap encoding fmt6 not ready');
        return [];
    }

    public static function fmt8($data, $offset)
    {
        throw new \Exception('cmap encoding fmt8 not ready');
        return [];
    }

    public static function fmt10($data, $offset)
    {
        throw new \Exception('cmap encoding fmt10 not ready');
        return [];
    }

    public static function fmt12($data, $offset)
    {
        $nGroups=unpack('Nng', $data, $offset+12)['ng'];
        $pos=$offset+16;
        $m=[];
        for($i=0;$i<$nGroups;++$i){
            $a=unpack('Nc/Ne/No', $data, $pos);
            $pos+=12;
            for($c=$a['c'],$e=$a['e'],$o=$a['o'];$c<=$e;++$c) {
                $g=$o++;
                if($g!==0) {
                    $m[$c]=$g;
                }
            }
        }
        return $m;
    }

    public static function fmt13($data, $offset)
    {
        throw new \Exception('cmap encoding fmt13 not ready');
        return [];
    }

    public static function fmt14($data, $offset)
    {
        throw new \Exception('cmap encoding fmt14 not ready');
        return [];
    }
}