<?php
require(__DIR__.'/../vendor/autoload.php');

use ren1244\PDFWriter\PDFWriter;
use ren1244\PDFWriter\PageMetrics;

$pdf=new PDFWriter;

//新增頁面
$pdf->addPage('A4');

//加入字型
//(如果要使用中文字型，先準備一個 ttf 字型檔，再透過 script/addTTF.php 轉檔後使用)
$pdf->font->addFont('Times-Roman');

//設定文字範圍(單位是 mm)
$pdf->text->setRect([10, 10, 100, 100]);

//寫入文字
$pdf->text->addText('Hello', 0, 'top');

//畫線 (這邊是畫一個三角形)
$pdf->postscriptGragh->addPath('20 20 m 10 40 l 30 40 l 20 20 l S', PageMetrics::getUnit(0.5));

//輸出
$pdf->output();
