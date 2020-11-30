<?php
namespace ren1244\PDFWriter;

/**
 * 1. $unit 是 static 變數，為使用者使用中的單位
 * 2. 提供一些單位轉換的 static 函式
 * 3. 每個頁面對應一個 PageMetrics instance，紀錄長寬，單位固定為 pt(和 $unit 無關)
 */
class PageMetrics
{
    public $width; //頁面寬度，單位固定為 pt
    public $height; //頁面高度，單位固定為 pt
    public static $unit='mm';

    /**
     * 將 num pt 轉換為目前單位
     * 
     * @param float $num pt 數值
     * @return float 目前單位數值
     */
    public static function getUnit($num) {
        switch(self::$unit) {
            case 'mm':
                $num*=25.4/72;
            break;
            case 'cm':
                $num*=2.54/72;
            break;
            default:
        }
        return $num;
    }
    
    /**
     * 將 num 目前單位 轉換為 pt
     * 
     * @param float $num 目前單位數值
     * @return float pt 數值
     */
    public static function getPt($num) {
        switch(self::$unit) {
            case 'mm':
                $num*=72/25.4;
            break;
            case 'cm':
                $num*=72/2.54;
            break;
            default:
        }
        return $num;
    }

    /**
     * 取得將目前單位轉換為pt的倍率
     * 
     * @return float 倍率
     */
    public static function getScale() {
        switch(self::$unit) {
            case 'mm':
                return 72/25.4;
            break;
            case 'cm':
                return 72/2.54;
            break;
            default:
        }
        return 1;
    }

    /**
     * 設定建立頁面為某長寬
     * 這邊的單位都是 pt
     * 
     * @param int $width 頁寬
     * @param int $height 頁高
     */
    public function __construct($width, $height)
    {
        $this->width=$width;
        $this->height=$height;
    }
}