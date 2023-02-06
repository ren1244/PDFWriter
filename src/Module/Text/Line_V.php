<?php

namespace ren1244\PDFWriter\Module\Text;

use Exception;

class Line_V extends LineAbstract
{
    /** @var Word_V */
    private $currentWord;

    private $width = 0;
    private $height = 0;
    private $maxHeight = 0;
    private $wordSpace = 0;
    /** @var Word_V[] */
    private $words = [];

    public static function setFontController($ftCtrl)
    {
        Word_V::setFontController($ftCtrl);
    }

    public function __construct($length1, $space1, $initialLength2)
    {
        $this->currentWord = new Word_V;
        $this->maxHeight = $length1;
        $this->wordSpace = $space1;
        $this->width = $initialLength2;
    }

    public function push($unicode)
    {
        if ($unicode === 10 || $unicode === 13) { // 換行
            $status = $this->pullWord();
            return ($status !== self::DISCARD ? $status | self::NEXT : self::DISCARD) | self::ENDLINE;
        }
        if($unicode === 32 || $unicode === 9) { // 空白
            $status = $this->pullWord();
            return $status !== self::DISCARD ? $status | self::NEXT : self::DISCARD | self::ENDLINE;
        }
        if ($this->currentWord->push($unicode)) { // 此字推送成功，仍需後續資料才能判斷
            return self::NEXT;
        }
        // 推送失敗，代表先前的 word 要先 pull
        $status = $this->pullWord();
        if ($status & self::DISCARD) { // 超出範圍並捨棄
            return $status | self::ENDLINE;
        }
        if (!$this->currentWord->push($unicode)) {
            throw new Exception('無法推送 char 到 word');
        }
        return $status | self::NEXT;
    }

    public function pullWord()
    {
        if ($this->currentWord->isEmpty()) {
            return 0;
        }
        // 如果剩餘的 word 不會超過範圍，加入此行
        $wordW = $this->currentWord->width;
        $wordH = $this->currentWord->height;
        if($this->currentWord->wMode === 1) {
            $wordH += ($this->currentWord->nChars - 1) * $this->wordSpace;
        }
        if(count($this->words) > 0) {
            $wordH += $this->wordSpace;
        }
        if ($this->height + $wordH <= $this->maxHeight) {
            $this->height += $wordH;
            if ($this->width < $wordW) {
                $this->width = $wordW;
            }
            if (!$this->currentWord->mergeTo($this->words)) {
                $this->words[] = $this->currentWord;
            }
            $this->currentWord = new Word_V;
            return self::ACCEPT;
        }
        $this->currentWord = new Word_V;
        return self::DISCARD;
    }

    public function postscript()
    {
        $x = $this->x;
        $y = $this->y;
        $result = [sprintf('BT 1 0 0 1 %.3f %.3f Tm', $x, $y)];
        $firstWordFlag = true;
        foreach ($this->words as $word) {
            if ($firstWordFlag) {
                $firstWordFlag = false;
            } else {
                $y -= $this->wordSpace;
            }
            if ($word->wMode === 1) {
                $result[] = "-$this->wordSpace Tc " . $word->postscript();
                $y -= $word->height + $this->wordSpace * ($word->nChars - 1);
            } else {
                $result[] = sprintf('0 Tc 1 0 0 1 %.3f %.3f Tm', $x - $word->width / 2, $y - $word->getAsc());
                $result[] = $word->postscript();
                $result[] = sprintf('1 0 0 1 %.3f %.3f Tm', $x, $y - $word->height - $this->wordSpace);
                $y -= $word->height;
            }
        }
        $result[] = "ET";
        return implode(' ', $result);
    }

    public function getLength1()
    {
        return $this->height;
    }

    public function getLength2()
    {
        return $this->width;
    }

    public function log()
    {
        echo "$this->width x $this->height | $this->maxHeight<br>";
        foreach ($this->words as $word) {
            echo "$word->width x $word->height | " . htmlspecialchars($word->postscript()) . '<br>';
        }
    }
}
