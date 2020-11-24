<?php
namespace ren1244\PDFWriter\FontLib;

class CFFLib
{
    const TOP_DICT_OPERATORS=[
        'version' => 0,
        'Notice' => 1,
        'Copyright' => 0xc00,
        'FullName' => 2,
        'FamilyName' => 3,
        'Weight' => 4,
        'isFixedPitch' => 0xc01,
        'ItalicAngle' => 0xc02,
        'UnderlinePosition' => 0xc03,
        'UnderlineThickness' => 0xc04,
        'PaintType' => 0xc05,
        'CharstringType' => 0xc06,
        'FontMatrix' => 0xc07,
        'UniqueID' => 13,
        'FontBBox' => 5,
        'StrokeWidth' => 0xc08,
        'XUID' => 14,
        'charset' => 15,
        'Encoding' => 16,
        'CharStrings' => 17,
        'Private' => 18,
        'SyntheticBase' => 0xc14,
        'PostScript' => 0xc15,
        'BaseFontName' => 0xc16,
        'BaseFontBlend' => 0xc17,
        'ROS' => 0xc1e,
        'CIDFontVersion' => 0xc1f,
        'CIDFontRevision' => 0xc20,
        'CIDFontType' => 0xc21,
        'CIDCount' => 0xc22,
        'UIDBase' => 0xc23,
        'FDArray' => 0xc24,
        'FDSelect' => 0xc25,
        'FontName' => 0xc26
    ];

    const PRIVATE_DICT_OPERATORS=[
        'BlueValues'=> 6,
        'OtherBlues'=> 7,
        'FamilyBlues'=> 8,
        'FamilyOtherBlues'=> 9,
        'BlueScale'=> 0xc09,
        'BlueShift'=> 0xc0a,
        'BlueFuzz'=> 0xc0b,
        'StdHW'=> 10,
        'StdVW'=> 11,
        'StemSnapH'=> 0xc0c,
        'StemSnapV'=> 0xc0d,
        'ForceBold'=> 0xc0e,
        'LanguageGroup'=> 0xc11,
        'ExpansionFactor'=> 0xc12,
        'initialRandomSeed'=> 0xc13,
        'Subrs'=> 19,
        'defaultWidthX'=> 20,
        'nominalWidthX'=> 21
    ];

    /**
     * 取得 index 內容的位置資訊
     * 
     * @param string $data 完整資料區塊
     * @param int $pos 從這個位置開始讀取
     * @return array {s:起始位置, e:結束位置+1, c:共幾個元素, arr:位置陣列(c為0時無此項)}
     */
    public static function unpackIndexPos($data, $pos)
    {
        $out=[];
        $count=ord($data[$pos])<<8|ord($data[$pos+1]);
        if($count===0) {
            $out=[
                's'=>$pos,
                'c'=>0,
                'e'=>$pos+2
            ];
            return $out;
        }
        $size=ord($data[$pos+2]);
        $startPos=$pos+2+$size*($count+1);
        $beginPos=$pos;
        $pos+=3;
        for($i=0;$i<=$count;++$i) {
            $r=0;
            for($j=0;$j<$size;++$j) {
                $r=$r<<8|ord($data[$pos++]);
            }
            $out[]=$r+$startPos;
        }
        $out=[
            's'=>$beginPos,
            'c'=>$count,
            'e'=>$out[$count],
            'arr'=>$out
        ];
        return $out;
    }

    /**
     * 把某區塊解開為關聯陣列
     * 
     * @param string $data 完整資料區塊
     * @param int $offset 從這位置開始解
     * @param int $length 資料長度
     * @param array $opArr operator 資訊， key=>opVal
     */
    public static function unpackDict($data, $offset, $length, $opArr)
    {
        $opArr=array_flip($opArr); //反轉為 opVal=>key
        $end=$offset+$length;
        $t=['0','1','2','3','4','5','6','7','8','9','.','E','E-','','-',false];
        $out=[];
        $vArr=[];
        $vCount=0;
        for($pos=$offset;$pos<$end;) {
            $b0=ord($data[$pos++]);
            if(32<=$b0 && $b0<=246) {
                $vArr[$vCount++]=$b0-139;
            } elseif(247<=$b0 && $b0<=250 && $pos+1<$end) {
                $b1=ord($data[$pos++]);
                $vArr[$vCount++]=($b0-247<<8|$b1)+108;
            } elseif(251<=$b0 && $b0<=254 && $pos+1<$end) {
                $b1=ord($data[$pos++]);
                $vArr[$vCount++]=-($b0-251<<8|$b1)-108;
            } elseif($b0===28 && $pos+2<$end) {
                $b1=ord($data[$pos++]);
                $b2=ord($data[$pos++]);
                $vArr[$vCount++]=$b1<<8|$b2;
            } elseif($b0===29 && $pos+4<$end) {
                $b1=ord($data[$pos++]);
                $b2=ord($data[$pos++]);
                $b3=ord($data[$pos++]);
                $b4=ord($data[$pos++]);
                $vArr[$vCount++]=$b1<<24|$b2<<16|$b3<<8|$b4;
            } elseif($b0===30) {
                $s='';
                do{
                    $b=ord($data[$pos++]);
                    if(($c=$t[$b>>4])===false) {
                        break;
                    }
                    $s.=$c;
                    if(($c=$t[$b&15])===false) {
                        break;
                    }
                    $s.=$c;
                } while($pos+1<$end);
                $vArr[$vCount++]=$s;
            } else {
                if($b0===12) {
                    if($pos+1>$end) {
                        throw new \Exception('錯誤的 operator');
                    }
                    $b0=$b0<<8|ord($data[$pos++]);
                }
                if(isset($opArr[$b0])) {
                    $out[$opArr[$b0]]=$vCount===1?$vArr[0]:$vArr;
                    $vArr=[];
                    $vCount=0;
                } else {
                    throw new \Exception('錯誤的 DICT 序列');
                }
            }
        }
        return $out;
    }

    /**
     * 將陣列內容編碼為 index 格式
     * 
     * @param array $arr 陣列，其元素為 binary string
     * @return string 編碼完成的 binary string
     */
    public static function packIndex($arr)
    {
        $n=count($arr);
        if($n===0) {
            return pack('n', 0);
        }
        $pos=1;
        $out=[1];
        foreach($arr as $d) {
            $pos+=strlen($d);
            $out[]=$pos;
        }
        if($pos<=0xFF) {
            return pack('nCC*', $n, 1, ...$out).implode('', $arr);
        } elseif($pos<=0xFFFF) {
            return pack('nCn*', $n, 2, ...$out).implode('', $arr);
        } else {
            return pack('nCN*', $n, 4, ...$out).implode('', $arr);
        }
    }

    /**
     * 將關聯陣列編碼
     * 
     * @param array $arr 要被編碼的內容
     * @param array $opArr operator 資訊， key=>opVal
     * @param array $fixedLenOps 固定長度的 operators，這些的值會用 uint32
     * @return string 編碼好的 binary string
     */
    public static function packDict($arr, $opArr, $fixedLenOps=[])
    {
        $realMap=[
            '0'=>0, '1'=>1, '2'=>2, '3'=>3, '4'=>4, '5'=>5,
            '6'=>6, '7'=>7, '8'=>8, '9'=>9, '.'=>0xa, '-'=>0xe
        ];
        $s='';
        foreach($arr as $k=>$v) {
            if(gettype($v)!=='array') {
                $v=[$v];
            }
            $fixedLen=in_array($k, $fixedLenOps);
            foreach($v as $x) {
                if(is_int($x)) {
                    if($fixedLen) { //固定長度
                        $s.=chr(29).chr($x>>24&0xFF).chr($x>>16&0xFF).chr($x>>8&0xFF).chr($x&0xFF);
                    } elseif(-107<=$x && $x<=107) {
                        $s.=chr($x+139);
                    } elseif(108<=$x && $x<=1131) {
                        $x-=108;
                        $s.=chr(($x>>8)+247).chr($x&0xFF);
                    } elseif(-1131<=$x && $x<=-108) {
                        $x=-$x-108;
                        $s.=chr(($x>>8)+251).chr($x&0xFF);
                    } elseif(-32768<=$x && $x<=32767) {
                        $s.=chr(28).chr($x>>8).chr($x&0xFF);
                    } else {
                        $s.=chr(29).chr($x>>24&0xFF).chr($x>>16&0xFF).chr($x>>8&0xFF).chr($x&0xFF);
                    }
                } else { //寫入浮點數(目前浮點數為字串)
                    $s.=chr(30);
                    $n=strlen($x);
                    $endFlag=false;
                    for($i=0;$i<$n;$i+=2) {
                        $c=$x[$i];
                        if(isset($realMap[$c])) {
                            $t=$realMap[$c]<<4;
                        } elseif($c==='E') {
                            if($i+1<$n && $x[$i+1]==='-') {
                                ++$i;
                                $t=0xc0;
                            } else {
                                $t=0xb0;
                            }
                        } else {
                            throw new \Exception('real encode error');
                        }
                        if($i+1>=$n) {
                            $s.=chr($t|0xf);
                            $endFlag=true;
                            break;
                        }
                        $c=$x[$i+1];
                        if(isset($realMap[$c])) {
                            $s.=chr($t|$realMap[$c]);
                        } elseif($c==='E') {
                            if($i+2<$n && $x[$i+2]==='-') {
                                ++$i;
                                $s.=chr($t|0xc);
                            } else {
                                $s.=chr($t|0xb);
                            }
                        } else {
                            throw new \Exception('real encode error');
                        }
                    }
                    if(!$endFlag) {
                        $s.=chr(0xff);
                    }
                }
            }
            $op=$opArr[$k];
            if($op>255) {
                $s.=chr($op>>8&255);
            }
            $s.=chr($op&255);
        }
        return $s;
    }

    /**
     * 預先計算 index 格式
     * 
     * @param array $arr 陣列，其元素為長度
     * @return string 總長度
     */
    public static function calIndexLen($arr)
    {
        $n=count($arr);
        if($n===0) {
            return 2;
        }
        $pos=1+array_sum($arr);
        if($pos<=0xFF) {
            return 2+($n+1)+$pos;
        } elseif($pos<=0xFFFF) {
            return 2+($n+1)*2+$pos;
        } else {
            return 2+($n+1)*4+$pos;
        }
    }

    /**
     * 預先計算 Dict 長度
     * 
     * @param array $arr 要被編碼的內容
     * @param array $opArr operator 資訊， key=>opVal
     * @param array $fixedLenOps 固定長度的 operators，這些的值會用 uint32
     * @return int 總長度
     */
    public static function calDictLen($arr, $opArr, $fixedLenOps=[])
    {
        $len=0;
        foreach($arr as $k=>$v) {
            if(gettype($v)!=='array') {
                $v=[$v];
            }
            $fixedLen=in_array($k, $fixedLenOps);
            foreach($v as $x) {
                if(is_int($x)) {
                    if($fixedLen) { //固定長度
                        $len+=5;
                    } elseif(-107<=$x && $x<=107) {
                        $len+=1;
                    } elseif(108<=$x && $x<=1131) {
                        $len+=2;
                    } elseif(-1131<=$x && $x<=-108) {
                        $len+=2;
                    } elseif(-32768<=$x && $x<=32767) {
                        $len+=3;
                    } else {
                        $len+=5;
                    }
                } else {
                    $n=strlen($x)-(strpos($x, 'E-')!==false?1:0);
                    $len+=($n+2>>1)+1;
                }
            }
            $op=$opArr[$k];
            if($op>255) {
                $len+=2;
            } else {
                $len+=1;
            }
        }
        return $len;
    }
}