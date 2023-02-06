<?php

namespace ren1244\PDFWriter\Module\Text;

abstract class LineAbstract
{
    //以下四個 const 為 push 與 pullWord 方法回傳的「狀態」
    const NEXT = 1; // 此 unicode 先放入 Word（也有可能被丟棄，例如換行字元），接下來讀下一個字元
    const ENDLINE = 2; // 此行應結束
    const ACCEPT = 4;  // 之前的幾個 unicode 被加入 Line，與 DISCARD 相斥
    const DISCARD = 8; // 之前的幾個 unicode 被忽略，應於下一行重新讀取，與 ACCEPT 相斥

    /** @var int 開始寫入位置的 x 座標 */
    public $x = 0;

    /** @var int 開始寫入位置的 y 座標 */
    public $y = 0;
    
    /**
     * __construct
     *
     * @param  mixed $length1        Line 長度的最大值
     * @param  mixed $space1         字距
     * @param  mixed $initialLength2 行高(橫書)或行寬(直書)的起始值(最小值)，避免空行不偏移而產生無窮迴圈
     * @return void
     */
    abstract public function __construct($length1, $space1, $initialLength2);
    
    /**
     * 推送一個字元進入此行
     * 實作時應先推送到相關的 Word 物件
     * 當 Word 成立時才紀錄此 Word
     *
     * @param  int $unicode unicode 數值
     * @return int 狀態
     */
    abstract public function push($unicode);

    /**
     * 從 word 拉取資料
     * 通常會被 push 方法呼叫(要紀錄 Word 物件的內容時)
     * 此外資料讀取到最後也可能被呼叫一次把剩餘的字抓出來
     *
     * @return int 狀態，可能的值有: ACCEPT, DISCARD, 0
     */
    abstract public function pullWord();
    
    /**
     * 產生 postscript 字串
     * 起始位置由 x, y 決定
     *
     * @return string
     */
    abstract public function postscript();
    
    /**
     * 取得 length1（橫書為寬度，直書為高度）
     *
     * @return int
     */
    abstract public function getLength1();
   
    /**
     * 取得 length2（橫書為高度，直書為寬度）
     *
     * @return int
     */
    abstract public function getLength2();
}
