<?php
namespace ren1244\PDFWriter;

class Text
{
    private $data=[];
    private $ftCtrl;
    private $posX=0;
    private $posY=0;
    private $rect=[0, 0, 65535, 65535];
    private $mtx;
    private $lineHeightScale=1;

    public function __construct(FontController $ftCtrl, PageMetrics $mtx)
    {
        $this->ftCtrl=$ftCtrl;
        $this->mtx=$mtx;
    }

    public function setRect($rect)
    {
        if(count($rect)!==4) {
            throw new \Exception('rect must has 4 integer element');
        }
        for($i=0;$i<4;++$i) {
            $rect[$i]=PageMetrics::getPt($rect[$i]);
        }
        $rect[1]=$this->mtx->height-$rect[3]-$rect[1];
        $this->rect=$rect;
    }

    public function addText($text, $x=false, $y=false, $opt=[])
    {
        if(isset($opt['lineHeight'])) {
            $this->lineHeightScale=$opt['lineHeight'];
        }
        $ftCtrl=$this->ftCtrl;
        $hInfo=$ftCtrl->getHeightInfo();
        $xMin=$this->rect[0];
        $xMax=$this->rect[2]+$xMin;
        $yMin=$this->rect[1];
        $yMax=$this->rect[3]+$yMin;
        $x=$x===false?$this->posX:PageMetrics::getPt($x)+$xMin;
        $y=$y===false?$this->posY:($y==='top'?$yMax-$hInfo['ascent']:$yMax-PageMetrics::getPt($y));
        $text=mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
        $n=strlen($text);
        $ftSizeTable=$ftCtrl->getSizeTable(); //從字型名稱映射到字型大小
        $lineHeight=max(array_values($ftSizeTable))*$this->lineHeightScale;
        //每次 push 注意以下要更新
        $sx=$x; //初始位置
        $sy=$y;
        $pos=0; //字串切片位置
        $len=0;
        $curPsName=false;

        for($i=0;$i<$n;$i+=2) {
            $c=(ord($text[$i])<<8|ord($text[$i+1]));
            if(0xd800<=$c && $c<=0xdbff) {
                $i+=2;
                $t=(ord($text[$i])<<8|ord($text[$i+1]));
                $c=($c-0xd800<<10|$t-0xdc00)+0x10000;
            }
            $w=$ftCtrl->getWidth($c, $psn);
            $dx=$w===false?0:$w;
            if($curPsName===false) {
                $curPsName=$psn;
            }
            $ftChangeFlag=$curPsName!==$psn?true:false;
            $outOfRangeFlag=$x+$dx>$xMax||$c===10?true:false;
            if($ftChangeFlag || $outOfRangeFlag) {
                if($sy+$hInfo['descent']<$yMin) {
                    $outOfRangeFlag=true;
                    break;
                }
                $this->data[]=[
                    'psName'=>$curPsName,
	                'size'=>$ftSizeTable[$curPsName],
	                'x'=>$sx,
	                'y'=>$sy,
	                'str'=>substr($text, $pos, $len)
                ];
                $this->posX=$x;
                $this->posY=$y;
                $pos+=$len+($c===10?2:0);
                $len=$c>0xffff?4:($c!==10?2:0);
                $curPsName=$psn;
                if($outOfRangeFlag) {
                    $sx=$xMin;
                    $x=$xMin+($c!==10?$dx:0);
                    $y-=$lineHeight;
                    $sy=$y;
                    if($sy+$hInfo['descent']<$yMin) {
                        $outOfRangeFlag=true;
                        break;
                    }
                } else {
                    $sx=$x;
                    $x+=$dx;
                }
            } else {
                $len+=$c>0xffff?4:2;
                $x+=$dx;
            }
        }
        if($len>0 && !$outOfRangeFlag) {
            $this->data[]=[
                'psName'=>$curPsName,
                'size'=>$ftSizeTable[$curPsName],
                'x'=>$sx,
                'y'=>$sy,
                'str'=>substr($text, $pos, $len)
            ];
            $this->posX=$x;
            $this->posY=$y;
        }
        
    }

    /**
     * 這個函式不由使用者呼叫
     */
    public function write($writer)
    {
        $s=[];
        foreach($this->data as $data) {
            $str=$this->ftCtrl->getTextContent($data['psName'], $data['str']);
            $ftName=$this->ftCtrl->getFontName($data['psName']);
            $s[]="BT /$ftName ${data['size']} Tf ${data['x']} ${data['y']} Td <$str> Tj ET";
        }
        return $writer->writeStream(implode(' ', $s), StreamWriter::COMPRESS);
    }
}