<?php

namespace ren1244\PDFWriter\Module\Text;

abstract class WordAbstract
{
    /** @var Fragment[] */
    public $fragments = [];

    /** @var int */
    public $width = 0;

    /** @var int */
    public $height = 0;

    /** @var int 橫書 = 0, 直書 = 1 */
    public $wMode = 0;

    /** @var int 共幾個字 */
    public $nChars = 0;

    /** @var FontController */
    protected static $ftCtrl = null;

    /**
     * setFontController
     *
     * @param  FontController $ftCtrl
     * @return void
     */
    public static function setFontController($ftCtrl)
    {
        self::$ftCtrl = $ftCtrl;
    }

    public function isEmpty() {
        return count($this->fragments) === 0;
    }

    public function getAsc() {
        if(count($this->fragments)=== 0) {
            return 0;
        }
        return $this->fragments[0]->asc;
    }

    /**
     * 推送進 word
     *
     * @param  int $unicode
     * @return bool 是否成功，例如英數遇到空格時，應回傳 false，而且不紀錄空格
     */
    abstract public function push($unicode);

    /**
     * 把目前這個 word 嘗試合併到 $wordArray 的最後一個元素
     * 
     * @param array &$wordArray
     * @return bool 是否合併成功
     */
    abstract public function mergeTo(&$wordArray);
    
    /**
     * 產生這個 Word 的 postscript
     *
     * @return string
     */
    abstract public function postscript();
}
