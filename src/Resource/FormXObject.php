<?php

namespace ren1244\PDFWriter\Resource;

use ren1244\PDFWriter\PageMetrics;
use ren1244\PDFWriter\StreamWriter;

class FormXObject implements ResourceInterface
{
    private $count=0;
    private $contents=[];
    /**
     * 回傳 id
     */
    public function registry(string $postscript, string $bbox, string $matrix)
    {
        $this->contents[] = [
            $postscript,
            $bbox,
            $matrix
        ];
        return '/FXObj'.($this->count++);
    }

    /**
     * 寫入 pdf 內容，並回傳要註冊於 Resource Dict 的 key 跟 value
     * 
     * @return array [key, value]
     */
    public function write(StreamWriter $writer)
    {
        $objIdArr=[];
        foreach($this->contents as $idx => $content) {
            $id = $writer->writeStream($content[0], StreamWriter::COMPRESS, [
                'Type'=> '/XObject',
                'Subtype'=> '/Form',
                'FormType'=> 1,
                'BBox'=> '['.$content[1].']',
                'Matrix'=> '['.$content[2].']',
                'Resources'=> '<< /ProcSet [ /PDF /Text ] >>'
            ]);
            $objIdArr[]="/FXObj$idx $id 0 R";
        }
        $objIdArr=implode(' ', $objIdArr);
        return ['XObject', $objIdArr];
    }

    /**
     * 使用者操作結束，要開始輸出 pdf 之前的預處理
     */
    public function preprocess()
    {
    }
}
