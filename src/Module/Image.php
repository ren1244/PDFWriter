<?php
namespace ren1244\PDFWriter\Module;

use ren1244\PDFWriter\StreamWriter;
use ren1244\PDFWriter\PageMetrics;
use ren1244\PDFWriter\Resource\ImageResource;

class Image
{
    private $mtx;
    private $imgRes;

    public function __construct(PageMetrics $mtx, ImageResource $imgRes)
    {
        $this->mtx=$mtx;
        $this->imgRes=$imgRes;
    }

    public function addImage($imgFile, $x=0, $y=0, $w=false, $h=false)
    {
        list($nameId, $imgW, $imgH)=$this->imgRes->addImage($imgFile);
        $this->_addImage($nameId, $imgW, $imgH, $x, $y, $w, $h);
    }

    public function addImageRaw($imgData, $x=0, $y=0, $w=false, $h=false)
    {
        list($nameId, $imgW, $imgH)=$this->imgRes->addImage($imgData, true);
        $this->_addImage($nameId, $imgW, $imgH, $x, $y, $w, $h);
    }

    public function write($writer, $data)
    {
        if(count($data)===0) {
            return false;
        }
        $streamArr=[];
        foreach($data as $imgData) {
            $y=$this->mtx->height - $imgData[2] - $imgData[4];
            $streamArr[]="q {$imgData[3]} 0 0 {$imgData[4]} {$imgData[1]} $y cm /{$imgData[0]} Do Q";
        }
        return $writer->writeStream(implode(' ', $streamArr));
    }

    private function _addImage($nameId, $imgW, $imgH, $x, $y, $w, $h)
    {
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
        $this->mtx->pushData($this, [$nameId, $x, $y, $w, $h]);
    }
}