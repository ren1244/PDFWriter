<?php
namespace ren1244\PDFWriter;

class Config
{
    //載入的 content module
    const Modules=[
        'image'=> Module\Image::class,
        'text' => Module\Text::class,
        'postscriptGragh'=> Module\PostscriptGragh::class,
        'textBox'=> Module\TextBox::class,
        'template' => Module\Template::class,
    ];

    //載入的 resource module
    const Resources=[
        'font' => Resource\FontController::class,
        'imageResource' => Resource\ImageResource::class,
        'formXObject' => Resource\FormXObject::class,
    ];

    //頁面尺寸名稱設定，注意這邊都是 pt
    const PAGE_SIZE=[
        'A4'=>[595.27559, 841.88976]
    ];

    //字型檔放置資料夾
    const FONT_DIR=__DIR__.'/../fonts';

    //用到 gzcompress 時，壓縮等級
    const GZIP_LEVEL=6;
}