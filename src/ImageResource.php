<?php
namespace ren1244\PDFWriter;

/**
 * 點陣圖的資源管理器
 * 目前支援 png 跟 jpeg
 * 其中 png 只剩下包含 tRns 的 tpye 0 跟 type 2 未處理
 * （這些格式應該不常見，之後再補完）
 */
class ImageResource
{
    private $id=0;
    private $images=[];

    public function addImage($nameOrData, $rawData=false)
    {
        if($rawData) {
            $info=getimagesizefromstring($nameOrData);
            $info['data']=$nameOrData;
        } else {
            if(!file_exists($nameOrData)) {
                throw new \Exception('image file does not exists');
            }
            $info=getimagesize($nameOrData);
            $info['filename']=$nameOrData;
        }
        
        $info['key']='IM'.(++$this->id);
        $this->images[]=$info;
        return [$info['key'], $info[0], $info[1]];
    }

    public function write($writer)
    {
        $arr=[];
        foreach($this->images as $info) {
            if($info['mime']==='image/jpeg') {
                $data=$info['data']??file_get_contents($info['filename']);
                $id=$writer->writeStream($data, false, [
                    'Type' => '/XObject',
                    'Subtype' => '/Image',
                    'BitsPerComponent' => $info['bits'],
                    'Width' => $info[0],
                    'Height' => $info[1],
                    'ColorSpace' => $info['channels']===3?'/DeviceRGB':'/DeviceCMYK',
                    'Filter' => '/DCTDecode'
                ]);
                $arr[]="/{$info['key']} $id 0 R";
            } elseif($info['mime']==='image/png') {
                $id=self::addPNG($info, $writer);
                $arr[]="/{$info['key']} $id 0 R";
            } else {
                throw new \Exception('unsupport image format');
            }
        }
        return '/XObject << '.implode("\n", $arr).' >>';
    }

    private static function addPNG($info, $writer)
    {
        $data=$info['data']??file_get_contents($info['filename']);
        $n=strlen($data);
        $idat='';
        $tRns=false;
        $idatLen=0;
        $plte=false;
        $plteLen=0;
        for($p=8;$p<$n;) {
            $sz=ord($data[$p])<<24|ord($data[$p+1])<<16|ord($data[$p+2])<<8|ord($data[$p+3]);
            $name=substr($data, $p+4, 4);
            $p+=8;
            if($name==='IHDR') {
                $info=unpack('Nw/Nh/Cdepth/Ctype/C3', substr($data, $p, $sz));
            } elseif($name==='IDAT') {
                $idat.=substr($data, $p, $sz);
                $idatLen+=1;
            } elseif($name==='PLTE') {
                $plte=[];
                for($i=0;$i<$sz;$i+=3){
                    $plte[]=bin2hex(substr($data, $p+$i, 3));
                }
                $plte=implode(' ', $plte);
                $plteLen=$sz;
            } elseif($name==='tRNS') {
                $tRns=substr($data, $p, $sz);
            }
            $p+=$sz+4;
        }
        $nColors=(($info['type']&3)===2?3:1)+($info['type']>>2);
        if(($info['type']&3)===3) {
            $colorSapce='[ /Indexed /DeviceRGB '.($plteLen/3-1).' <'.$plte.'> ]';
        } else {
            $colorSapce=($info['type']&3)===0?'/DeviceGray':'/DeviceRGB';
        }
        $maskId=false;
        if($info['type']>3) {
            $h=$info['h'];
            $w=$info['w'];
            $depth=$info['depth']/8;
            //重新包裝 idat 並取出 trns
            $idat=gzuncompress($idat);
            $bpp=$depth*$nColors; //depth=8 或 16 故為 4 或 8
            $bpp1=$depth*($nColors-1);
            $rowLen=1+$bpp*$w;
            $totalLen=$rowLen*$h;
            if($totalLen!==strlen($idat)) {
                $ll=strlen($idat);
                throw new \Exception('長度異常');
            }

            $trns='';
            // first row
            $a=[0,0];$b=[0,0];$c=[0,0];
            $filterType=ord($idat[0]);
            if($filterType===1 || $filterType===4) {
                $pIdat2=$pIdat=1+$bpp1;
                for($k=0;$k<$depth;++$k) {
                    $a[$k]=ord($idat[$pIdat2++]);
                    $trns.=chr($a[$k]);
                }
                for($i=1; $i<$w; ++$i) {
                    for($k=0;$k<$bpp1;++$k) {
                        $idat[$pIdat++]=$idat[$pIdat2++];
                    }
                    for($k=0;$k<$depth;++$k) {
                        $a[$k]=ord($idat[$pIdat2++])+$a[$k]&255;
                        $trns.=chr($a[$k]);
                    }
                }
            } elseif($filterType===2 || $filterType===0) {
                $pIdat=$pIdat2=1;
                for($i=0; $i<$w; ++$i) {
                    for($k=0;$k<$bpp1;++$k) {
                        $idat[$pIdat++]=$idat[$pIdat2++];
                    }
                    for($k=0;$k<$depth;++$k) {
                        $trns.=$idat[$pIdat2++];
                    }
                }
            } elseif($filterType===3) {
                $pIdat2=$pIdat=1+$bpp1;
                for($k=0;$k<$depth;++$k) {
                    $a[$k]=ord($idat[$pIdat2++]);
                    $trns.=chr($a);
                }
                for($i=1; $i<$w; ++$i) {
                    for($k=0;$k<$bpp1;++$k) {
                        $idat[$pIdat++]=$idat[$pIdat2++];
                    }
                    for($k=0;$k<$depth;++$k) {
                        $a[$k]=ord($idat[$pIdat2++])+($a[$k]>>1)&255;
                        $trns.=chr($a[$k]);
                    }
                }
            }
            $pTrns=0;
            //第一行之後
            for($p=$rowLen;$p<$totalLen;$p+=$rowLen) {
                $idat[$pIdat++]=$idat[$pIdat2++]; //複製 filter
                $filterType=ord($idat[$p]);
                $a[0]=$a[1]=$c[0]=$c[1]=0;
                if($filterType===1) {
                    for($i=0; $i<$w; ++$i) {
                        for($k=0;$k<$bpp1;++$k) {
                            $idat[$pIdat++]=$idat[$pIdat2++];
                        }
                        for($k=0;$k<$depth;++$k) {
                            $a[$k]=ord($idat[$pIdat2++])+$a[$k]&255;
                            $trns.=chr($a[$k]);
                        }
                    }
                    $pTrns+=$w*$depth;
                } elseif($filterType===2) {
                    for($i=0; $i<$w; ++$i) {
                        for($k=0;$k<$bpp1;++$k) {
                            $idat[$pIdat++]=$idat[$pIdat2++];
                        }
                        for($k=0;$k<$depth;++$k) {
                            $b[$k]=ord($trns[$pTrns++]);
                            $a[$k]=ord($idat[$pIdat2++])+$b[$k]&255;
                            $trns.=chr($a[$k]);
                        }
                    }
                } elseif($filterType===3) {
                    for($i=0; $i<$w; ++$i) {
                        for($k=0;$k<$bpp1;++$k) {
                            $idat[$pIdat++]=$idat[$pIdat2++];
                        }
                        for($k=0;$k<$depth;++$k) {
                            $b[$k]=ord($trns[$pTrns++]);
                            $a[$k]=ord($idat[$pIdat2++])+($a[$k]+$b[$k]>>1)&255;
                            $trns.=chr($a[$k]);
                        }
                    }
                } elseif($filterType===4) {
                    for($i=0; $i<$w; ++$i) {
                        for($k=0;$k<$bpp1;++$k) {
                            $idat[$pIdat++]=$idat[$pIdat2++];
                        }
                        for($k=0;$k<$depth;++$k) {
                            $ta=$a[$k];
                            $tb=$b[$k]=ord($trns[$pTrns++]);
                            $tc=$c[$k];
                            $pp=$ta+$tb-$tc;
                            $pa=$pp>$ta?$pp-$ta:$ta-$pp;
                            $pb=$pp>$tb?$pp-$tb:$tb-$pp;
                            $pc=$pp>$tc?$pp-$tc:$tc-$pp;
                            $a[$k]=ord($idat[$pIdat2++])+(
                                $pa<=$pb && $pa<=$pc?$ta:
                                ($pb<=$pc?$tb:$tc)
                            )&255;
                            $trns.=chr($a[$k]);
                            $c[$k]=$b[$k];
                        }
                    }
                } else {
                    for($i=0; $i<$w; ++$i) {
                        for($k=0;$k<$bpp1;++$k) {
                            $idat[$pIdat++]=$idat[$pIdat2++];
                        }
                        for($k=0;$k<$depth;++$k) {
                            $trns.=$idat[$pIdat2++];
                        }
                    }
                    $pTrns+=$w*$depth;
                }
            }
            --$nColors;
            $idat=substr($idat, 0, ($bpp1*$w+1)*$h);
            $idatLen=strlen($idat);
            $idat=gzcompress($idat);
            $maskId=$writer->writeStream($trns, StreamWriter::COMPRESS, [
                'Type' => '/XObject',
                'Subtype' => '/Image',
                'BitsPerComponent' => $info['depth'],
                'Width' => $info['w'],
                'Height' => $info['h'],
                'ColorSpace' => '/DeviceGray',
                'Decode' => '[0 1]'
            ]);
        }
        if($tRns && $info['type']===3) {//只處理索引色的 tRns
            $idatOrg=$idat;
            $idat=gzuncompress($idat);
            $h=$info['h'];
            $w=$info['w'];
            $depth=$info['depth'];
            $tRns=str_pad($tRns, 1<<$depth, chr(255)); //color index => 透明度
            $trns=''; //透明度(和上面大小寫不同)
            $pTrns=0;
            $pIdat=1;
            $filterType=ord($idat[0]);
            $nBytes=$depth*$w+7>>3;
            $nPixels=8/$depth;
            $mask=255>>8-$depth;
            if($filterType===1 || $filterType===4) {
                $a=0;
                $pCount=0;
                for($i=0;$i<$nBytes;++$i) {
                    $byte=ord($idat[$pIdat++]);
                    for($k=0;$k<$nPixels && $pCount++<$w;++$k) {
                        $a=($byte>>8-($k+1)*$depth&$mask)+$a&255;
                        $trns.=$tRns[$a];
                    }
                }
            } elseif($filterType===2 || $filterType===0) {
                $a=$b=0;
                $pCount=0;
                for($i=0;$i<$nBytes;++$i) {
                    $byte=ord($idat[$pIdat++]);
                    for($k=0;$k<$nPixels && $pCount++<$w;++$k) {
                        $a=($byte>>8-($k+1)*$depth&$mask);
                        $trns.=$tRns[$a];
                    }
                }
            } elseif($filterType===3) {
                $a=$b=0;
                $pCount=0;
                for($i=0;$i<$nBytes;++$i) {
                    $byte=ord($idat[$pIdat++]);
                    for($k=0;$k<$nPixels && $pCount++<$w;++$k) {
                        $a=($byte>>8-($k+1)*$depth&$mask)+($a>>1)&255;
                        $trns.=$tRns[$a];
                    }
                }
            }
            $pTrns=$w;
            $rowLen=(1+$nBytes);
            $totalLen=$rowLen*$h;
            for($p=$rowLen; $p<$totalLen; $p+=$rowLen) {
                $filterType=ord($idat[$pIdat++]);
                if($filterType===1) {
                    $a=0;
                    $pCount=0;
                    for($i=0;$i<$nBytes;++$i) {
                        $byte=ord($idat[$pIdat++]);
                        for($k=0;$k<$nPixels && $pCount++<$w;++$k) {
                            $a=($byte>>8-($k+1)*$depth&$mask)+$a&255;
                            $trns.=$tRns[$a];
                        }
                    }
                    $pTrns+=$w;
                } elseif($filterType===2) {
                    $pCount=0;
                    for($i=0;$i<$nBytes;++$i) {
                        $byte=ord($idat[$pIdat++]);
                        for($k=0;$k<$nPixels && $pCount++<$w;++$k) {
                            $b=ord($trns[$pTrns++]);
                            $a=($byte>>8-($k+1)*$depth&$mask)+$b&255;
                            $trns.=$tRns[$a];
                        }
                    }
                } elseif($filterType===3) {
                    $pCount=0;
                    $a=0;
                    for($i=0;$i<$nBytes;++$i) {
                        $byte=ord($idat[$pIdat++]);
                        for($k=0;$k<$nPixels && $pCount++<$w;++$k) {
                            $b=ord($trns[$pTrns++]);
                            $a=($byte>>8-($k+1)*$depth&$mask)+($a+$b>>1)&255;
                            $trns.=$tRns[$a];
                        }
                    }
                } elseif($filterType===4) {
                    $pCount=0;
                    $a=$c=0;
                    for($i=0;$i<$nBytes;++$i) {
                        $byte=ord($idat[$pIdat++]);
                        for($k=0;$k<$nPixels && $pCount++<$w;++$k) {
                            $b=ord($trns[$pTrns++]);
                            $pp=$a+$b-$c;
                            $pa=$pp>$a?$pp-$a:$a-$pp;
                            $pb=$pp>$b?$pp-$b:$b-$pp;
                            $pc=$pp>$c?$pp-$c:$c-$pp;
                            $a=($byte>>8-($k+1)*$depth&$mask)+(
                                $pa<=$pb && $pa<=$pc?$a:
                                ($pb<=$pc?$b:$c)
                            )&255;
                            $trns.=$tRns[$a];
                            $c=$b;
                        }
                    }
                } else {
                    $pCount=0;
                    for($i=0;$i<$nBytes;++$i) {
                        $byte=ord($idat[$pIdat++]);
                        for($k=0;$k<$nPixels && $pCount++<$w;++$k) {
                            $a=($byte>>8-($k+1)*$depth&$mask);
                            $trns.=$tRns[$a];
                        }
                    }
                    $pTrns+=$w;
                }
            }
            $idat=$idatOrg;
            $maskId=$writer->writeStream($trns, StreamWriter::COMPRESS, [
                'Type' => '/XObject',
                'Subtype' => '/Image',
                'BitsPerComponent' => 8,
                'Width' => $info['w'],
                'Height' => $info['h'],
                'ColorSpace' => '/DeviceGray',
                'Decode' => '[0 1]'
            ]);
        }
        $xObjDict=[
            'Type' => '/XObject',
            'Subtype' => '/Image',
            'BitsPerComponent' => $info['depth'],
            'Width' => $info['w'],
            'Height' => $info['h'],
            'ColorSpace' => $colorSapce,
            'Filter' => '/FlateDecode',
            'DecodeParms' => '<< /Predictor 15 /Colors '.$nColors.' /BitsPerComponent '.$info['depth'].' /Columns '.$info['w'].' >>',
        ];
        if($maskId) {
            $xObjDict['SMask']="$maskId 0 R";
        }
        return $writer->writeStream($idat, false, $xObjDict);
    }
}
