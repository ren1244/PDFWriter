<?php
namespace ren1244\PDFWriter;

class PostscriptGragh
{
    private $mtx;
    private $data=[];

    public function __construct(PageMetrics $mtx)
    {
        $this->mtx=$mtx;
    }

    public function addPath($postScript, $lineWidth)
    {
        $this->data[]=[
            'lineWidth'=>$lineWidth,
            'scale'=>PageMetrics::getScale(),
            'data'=>$postScript
        ];
    }

    public function write($writer)
    {
        $h=$this->mtx->height;
        foreach($this->data as $idx=>$data) {
            $scale=$data['scale'];
            $w=$data['lineWidth'];
            $content=$data['data'];
            $this->data[$idx]="q $scale 0 0 -$scale 0 $h cm $w w $content Q";
        }
        return $writer->writeStream(implode(' ', $this->data), StreamWriter::COMPRESS);
    }
}