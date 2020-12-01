<?php
namespace ren1244\PDFWriter\Module;

use ren1244\PDFWriter\StreamWriter;
use ren1244\PDFWriter\PageMetrics;

class PostscriptGragh
{
    private $mtx;

    public function __construct(PageMetrics $mtx)
    {
        $this->mtx=$mtx;
    }

    public function addPath($postScript, $lineWidth)
    {
        $this->mtx->pushData($this, [
            'lineWidth'=>$lineWidth,
            'scale'=>PageMetrics::getScale(),
            'data'=>$postScript
        ]);
    }

    public function write($writer, $datas)
    {
        $h=$this->mtx->height;
        foreach($datas as $idx=>$data) {
            $scale=$data['scale'];
            $w=$data['lineWidth'];
            $content=$data['data'];
            $datas[$idx]="q $scale 0 0 -$scale 0 $h cm $w w $content Q";
        }
        return $writer->writeStream(implode(' ', $datas), StreamWriter::COMPRESS);
    }
}