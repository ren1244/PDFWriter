<?php

namespace ren1244\PDFWriter\Module\Text;

/**
 * 代表一小段相同樣式的文字
 */
class Fragment
{
    public $font;    // 字型名稱
    public $size;    // 字型大小
    public $text;    // 文字內容
    public $asc;     // typoDescender

    public function __construct($font, $size, $asc, $text)
    {
        $this->font = $font;
        $this->size = $size;
        $this->asc = $asc;
        $this->text = $text;
    }
}
