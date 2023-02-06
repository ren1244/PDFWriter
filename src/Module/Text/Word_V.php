<?php

namespace ren1244\PDFWriter\Module\Text;

use Exception;
use ren1244\PDFWriter\Resource\FontController;

/**
 * 直書模式的 Word
 */
class Word_V extends WordAbstract
{
    /** @var Fragment|null */
    private $currentFrag = null;
    private $en = false;

    public function push($unicode)
    {
        if(isset(FontController::VMODE_REPLACE[$unicode])) {
            $unicode = FontController::VMODE_REPLACE[$unicode];
        }
        if ($this->currentFrag === null) {
            $this->en = $this->isEn($unicode);
            $this->wMode = $this->en ? 0 : 1;
            ++$this->nChars;
            $this->appendChar($unicode);
            return true;
        }
        if ($this->en && $this->isEn($unicode)) {
            ++$this->nChars;
            $this->appendChar($unicode);
            return true;
        }
        return false;
    }

    public function mergeTo(&$wordArray)
    {
        if (($n = count($wordArray)) === 0) {
            return false;
        }
        /** @var Word_V */
        $prevWord = $wordArray[$n - 1];
        if ($prevWord->wMode === 0 || $this->wMode === 0) { // 只合併直書
            return false;
        }
        $prevFrag = $prevWord->fragments[count($prevWord->fragments) - 1];
        $s = '';
        foreach ($this->fragments as $frag) {
            if ($prevFrag->font !== $frag->font) {
                return false;
            }
            $s .= $frag->text;
        }
        $prevFrag->text .= $s;
        $prevWord->height += $this->height;
        if ($prevWord->width < $this->width) {
            $prevWord->width = $this->width;
        }
        $prevWord->nChars += $this->nChars;
        return true;
    }

    public function postscript()
    {
        $result = [];
        foreach ($this->fragments as $frag) {
            $pdffont = self::$ftCtrl->getFontName($frag->font);
            if (!$this->en) {
                $pdffont .= '_V';
            }
            $frag->text = self::$ftCtrl->getTextContent($frag->font, $frag->text);
            $result[] = "/$pdffont $frag->size Tf <$frag->text> Tj";
        }
        return implode(' ', $result);
    }

    private function isEn($unicode)
    {
        return (48 <= $unicode && $unicode <= 57) || (65 <= $unicode && $unicode <= 90) || (97 <= $unicode && $unicode <= 122);
    }

    private function appendChar($unicode)
    {
        // 以 UTF-16BE 添加到字串
        if ($unicode < 0x10000) {
            $s = pack('n', $unicode);
        } else {
            $x = $unicode - 0x10000;
            $s = pack('n2', 0xd800 + ($x >> 10 & 0x3ff), 0xdc00 + ($x & 0x3ff));
        }
        // 依據字型取得寬度、高度等資訊
        $ftname = '';
        $w = self::$ftCtrl->getWidth($unicode, $ftname);
        if ($w === false) {
            $w = 0;
        }
        $info = self::$ftCtrl->getFontInfo($ftname);
        // 寫入到 fragments
        $sz = $info['size'];
        if (
            $this->currentFrag === null ||
            $this->currentFrag->font !== $ftname ||
            $this->currentFrag->size !== $sz
        ) {
            $this->currentFrag = new Fragment($ftname, $sz, $info['typoAscender'], $s);
            $this->fragments[] = $this->currentFrag;
        } else {
            $this->currentFrag->text .= $s;
        }
        // 更新 bbox, （直書，英數不旋轉）
        if ($this->en) {
            if ($this->height < $info['height']) {
                $this->height = $info['height'];
            }
            $this->width += $w;
        } else {
            if ($this->width < $w) {
                $this->width = $w;
            }
            $this->height += $info['height'];
        }
    }
}
