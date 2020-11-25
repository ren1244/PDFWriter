<?php
namespace ren1244\PDFWriter\Module;

use ren1244\PDFWriter\StreamWriter;
use ren1244\PDFWriter\PageMetrics;
use ren1244\PDFWriter\ImageResource;

class Image
{
    private $mtx;
    private $imgRes;
    private $data=[];

    public function __construct(PageMetrics $mtx, ImageResource $imgRes)
    {
        $this->mtx=$mtx;
        $this->imgRes=$imgRes;
    }

    public function addImage($jpegFile, $x=0, $y=0, $w=false, $h=false)
    {
        list($nameId, $imgW, $imgH)=$this->imgRes->addImage($jpegFile);
        $x=PageMetrics::getPt($x);
        $y=PageMetrics::getPt($y);
        if($w===false) {
            if($h===false) {
                $w=$imgW;
                $h=$imgH;
            } else {
                $h=PageMetrics::getPt($h);
                $w=$h*$imgW/$imgH;
            }
        } else {
            $w=PageMetrics::getPt($w);
            $h=$h===false?$w*$imgH/$imgW:PageMetrics::getPt($h);
        }
        $this->data[]=[$nameId, $x, $y, $w, $h];
    }

    public function write($writer)
    {
        if(count($this->data)===0) {
            return false;
        }
        $streamArr=[];
        foreach($this->data as $imgData) {
            $y=$this->mtx->height - $imgData[2] - $imgData[4];
            $streamArr[]="q {$imgData[3]} 0 0 {$imgData[4]} {$imgData[1]} $y cm /{$imgData[0]} Do Q";
        }
        return $writer->writeStream(implode(' ', $streamArr));
    }
}