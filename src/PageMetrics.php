<?php
namespace ren1244\PDFWriter;

class PageMetrics
{
    public $width;
    public $height;
    public static $unit='mm';

    public static function Pt($num) {
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

    public function __construct($width, $height)
    {
        $this->width=$width;
        $this->height=$height;
    }
}