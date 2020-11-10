<?php
namespace ren1244\PDFWriter;

class Config
{
    const Modules=[
        'text' => Text::class,
        'postscriptGragh'=>PostscriptGragh::class,
        'textBox'=>TextBox::class,
    ];
    const PAGE_SIZE=[
        'A4'=>[595.27559, 841.88976]
    ];
    const FONT_DIR=__DIR__.'/../fonts';
    const GZIP_LEVEL=6;
}