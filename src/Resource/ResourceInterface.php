<?php

namespace ren1244\PDFWriter\Resource;

use ren1244\PDFWriter\StreamWriter;

interface ResourceInterface
{
    /**
     * 寫入 pdf 內容，並回傳要註冊於 Resource Dict 的 key 跟 value
     * 
     * @return array [key, value]
     */
    public function write(StreamWriter $writer);

    /**
     * 使用者操作結束，要開始輸出 pdf 之前的預處理
     */
    public function preprocess();
}
