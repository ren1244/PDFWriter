<?php
namespace ren1244\PDFWriter;

class Config
{
    const Modules=[
        'image'=> Module\Image::class,
        'text' => Module\Text::class,
        'postscriptGragh'=> Module\PostscriptGragh::class,
        'textBox'=> Module\TextBox::class,
    ];
    const PAGE_SIZE=[
        'A4'=>[595.27559, 841.88976]
    ];
    const FONT_DIR=__DIR__.'/../fonts';
    const GZIP_LEVEL=6;
}