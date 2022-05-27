<?php
require(__DIR__.'/../vendor/autoload.php');

use ren1244\PDFWriter\PDFWriter;
use ren1244\PDFWriter\PageMetrics;
use ren1244\PDFWriter\Encryption\Rc4_128;
use ren1244\PDFWriter\Encryption\Aes128;
use ren1244\PDFWriter\Encryption\Aes256;

$pdf=new PDFWriter;

/**
 * 設定密碼與權限
 * 加密方式可以用：Aes256、Aes128、Rc4_128
 * 權限參考 PDFWriter::PERM_*
 */
$pdf->setEncryption(Aes256::class, 'user', 'owner', PDFWriter::PERM_ALL);

//開始寫入內容
$pdf->addPage('A4');
$pdf->font->addFont('Times-Roman');
$pdf->text->setRect(10, 10, 100, 100);
$pdf->text->addText('Hello', 0, 'top');
$pdf->postscriptGragh->addPath('20 20 m 10 40 l 30 40 l 20 20 l S', PageMetrics::getUnit(0.5));

//輸出
$pdf->output();
