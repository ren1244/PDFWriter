<?php
namespace ren1244\PDFWriter;

class TextBox
{
    public function __construct(Text $text, PostscriptGragh $gragh)
    {
        $this->text=$text;
        $this->gragh=$gragh;
    }

    public function add($text, $x, $y, $w, $h, $opt)
    {
        $pad=$opt['padding']??0;
        $lineHeight=$opt['lineHeight']??1;
        $this->text->setRect([$x+$pad, $y+$pad, $w-$pad*2, $h-$pad*2]);
        $this->text->addText($text, 0, 'top', ['lineHeight'=>$lineHeight]);
        $x2=$x+$w;
        $y2=$y+$h;
        $this->gragh->addPath("$x $y m $x2 $y l $x2 $y2 l $x $y2 l $x $y l S", PageMetrics::Pt(0.5));
    }

    public function write($writer)
    {
        return false;
    }
}