<?php

namespace ren1244\PDFWriter\Module;

use ren1244\PDFWriter\StreamWriter;
use ren1244\PDFWriter\PageMetrics;
use ren1244\PDFWriter\Resource\FormXObject;

class Template implements ModuleInterface
{
    private $page;
    private $fxObj;
    static $nameIdMap = []; //name => id

    /**
     * 建構函式
     * 藉由參數注入依賴的物件
     */
    public function __construct(PageMetrics $page, FormXObject $fxObj)
    {
        $this->page = $page;
        $this->fxObj = $fxObj;
    }

    public function registry(string $name, string $postscript, string $bbox, string $matrix = '1 0 0 1 0 0')
    {
        if (isset(self::$nameIdMap[$name])) {
            throw new \Exception('此名稱已被使用');
        }
        self::$nameIdMap[$name] = $this->fxObj->registry($postscript, $bbox, $matrix);
    }

    public function draw(string $name, $matrix=false)
    {
        if (!isset(self::$nameIdMap[$name])) {
            throw new \Exception('此名稱尚未定義');
        }
        $objId=self::$nameIdMap[$name];
        if($matrix) {
            $this->page->pushData($this, "q $matrix cm $objId Do Q");
        } else {
            $this->page->pushData($this, "q $objId Do Q");
        }
    }

    /**
     * 利用 $writer 寫入內容到 pdf
     * 
     * @return int|array pdf object id 或其陣列，這些 id 會被寫入 page 的 content 屬性
     */
    public function write(StreamWriter $writer, array $datas)
    {
        return $writer->writeStream(implode(' ', $datas), StreamWriter::COMPRESS);
    }
}
