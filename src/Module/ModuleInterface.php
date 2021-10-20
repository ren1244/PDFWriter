<?php
namespace ren1244\PDFWriter\Module;

use ren1244\PDFWriter\StreamWriter;
use ren1244\PDFWriter\PageMetrics;

interface ModuleInterface
{
    /**
     * 建構函式
     * 藉由參數注入依賴的物件
     */
    //public function __construct('inject resources、modules or PageMetrics class here');
    
    /**
     * 利用 $writer 寫入內容到 pdf
     * 
     * @return int|array pdf object id 或其陣列，這些 id 會被寫入 page 的 content 屬性
     */
    public function write(StreamWriter $writer, array $data);
}