<?php
namespace ren1244\PDFWriter\FontLib;

use ren1244\PDFWriter\Config;

class StandardFont
{
    private $info;
    private $usedChar;
    /**
     * 依據 $psName 初始化
     * 
     * @param string $psName 字型辨識名稱
     */
    public function __construct($psName)
    {
        $this->psName=$psName;
        $jsonFile=Config::FONT_DIR.'/standard/'.$psName.'.json';
        if(!file_exists($jsonFile)) {
            throw new \Exception("Standatd font $psName not exsits");
        }
        $this->info=json_decode(file_get_contents($jsonFile), true);
    }

    /**
     * 取得類型，讓 FontController 類別作為產生 pdf 內容的依據
     * @return int 0 for Standard, 1 for TrueType
     */
    public function getType()
    {
        return 0;
    }

    /**
     * 取得該 unicode 的寬度，如果超出字型範圍，回傳 false
     * （同時請記錄使用的 unicode，以供 subset 使用）
     * 
     * @param int $unicode unicode code point
     * @return int|false
     */
    public function getWidth($unicode)
    {
        $w=$this->info['w'][$unicode]??false;
        if($w===false || $w===0) {
            return false;
        }
        $this->usedChar[$unicode]=true;
        return $w;
    }

    /**
     * 回傳字型資訊
     */
    public function getMtx()
    {
        return $this->info['info'];
    }

    /**
     * 回傳字型資料(壓縮後的)
     */
    public function getProgram()
    {
        //standard font 用不到
    }

    /**
     * 取得使用到文字的 utc 陣列
     */
    public function getW()
    {
        $ctw=[];
        $w=$this->info['w'];
        foreach($this->usedChar as $u=>$x) {
            $ctw[$u]=$w[$u];
        }
        return $ctw;
    }

    /**
     * 取得使用到文字的 ctu 映射表
     * 這會用來產生 pdf Font 的 /ToUnicode entry
     * 
     * @return array 回傳 cid => unicode 映射表
     */
    public function getCTU()
    {
        //standard font 用不到
    }

    //將 str 轉換為 code，這必須在 subset 之後呼叫
    public function getText($str)
    {
        $str=mb_convert_encoding($str, 'UTF-8', 'UTF-16');
        return bin2hex($str);
    }

    //依據曾經 getWidth 的 unicodes，建立 subset
    public function subset()
    {
        //standard font 用不到
    }
}