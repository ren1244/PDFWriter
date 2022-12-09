<?php
namespace ren1244\PDFWriter\FontLib;

interface Font
{
    /**
     * 依據 $psName 初始化
     * 
     * @param string $psName 字型辨識名稱
     */
    public function __construct($psName, $ftJson);

    /**
     * 取得該 unicode 的寬度，如果超出字型範圍，回傳 false
     * （同時請記錄使用的 unicode，以供 subset 使用）
     * 
     * @param int $unicode unicode code point
     * @return int|false
     */
    public function getWidth($unicode);

    /**
     * 回傳字型資訊，包含：
     * bbox: 陣列 [xMin, yMin, xMax, yMax]
     * italicAngle: 斜體角度
     * ascent: 基線以上高度
     * descent: 基線以下高度
     * capHeight: capHeight（稍微小於或等於 ascent）
     * typoAscent:  排版時的 Ascent
     * typoDescent: 排版時的 Descent
     * typoLineGap: 排版時的 LineGap
     * 
     * @return array 字型資訊
     */
    public function getMtx();

    //========以下為開始 write 後會被呼叫========
    
    /**
     * 取得類型，讓 FontController 類別作為產生 pdf 內容的依據
     * @return int 0 for Standard, 1 for TrueType
     */
    public function getType();

    /**
     * 依據曾經 getWidth 的 unicodes，建立 subset
     */
    public function subset();

    //========以下為 subset 後會被呼叫========

    /**
     * 回傳字型真正的名稱(不一定是檔名)
     * 
     * @return string 字型名稱
     */
    public function getPostscriptName();

    /**
     * 回傳字型資料(用 gzcompress 壓縮後的)
     * 
     * @return string 字型資料
     */
    public function getProgram();

    /**
     * 取得使用到文字的 utw 映射表
     * 這會用來產生 pdf Font 的 /W entry
     * 雖然全字型的也可
     * 但只取 subset 檔案較小
     * 
     * @return array 回傳 utw 映射表
     */
    public function getW();

    /**
     * 取得使用到文字的 ctu 映射表
     * 這會用來產生 pdf Font 的 /ToUnicode entry
     * 
     * @return array 回傳 cid => unicode 映射表
     */
    public function getCTU();

    /**
     * 回傳字型資訊，包含：
     * getMtx() 的所有內容外加 size
     * size: subset後字型檔的原始大小(未壓縮前)
     * 
     * @return array 字型資訊
     */
    public function getInfo();

    /**
     * 依照 subset 後，新生成的 utc 表
     * 將字串轉換為對應的 hex string
     * 
     * @param string $str UTF-16BE 編碼的字串
     * @return string 作為 pdf 文字內容的 hex-string
     */
    public function getText($str);
}