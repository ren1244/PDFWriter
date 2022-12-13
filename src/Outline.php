<?php

namespace ren1244\PDFWriter;

use ren1244\PDFWriter\StreamWriter;

class Outline
{
    const NORMAL = 0;
    const ITALIC = 1;
    const BOLD = 2;

    /** @var string|null */
    private $title = null;

    /** @var int|null 連結到第幾頁 */
    private $page = null;

    /** @var float 跳到 y 位置 */
    private $y = 0;

    /** @var int */
    private $style = 0;

    /** @var string|null */
    private $color = null;

    /** @var array 子節點 */
    private $children = [];

    /** @var int 在 pdf 中的 id */
    private $currentId = null;

    /** @var int|null */
    private $parentId = null;

    /** @var int|null */
    private $prevId = null;

    /** @var int|null */
    private $nextId = null;

    /** @var int|null */
    private $firstId = null;

    /** @var int|null */
    private $lastId = null;

    /** @var int|null */
    private $count = null;

    /**
     * 增加一個書籤
     *
     * @param  string $title 書籤文字
     * @param  int|null $page 跳到第幾頁，若為 null 代表點擊時不跳頁
     * @param  mixed $y 跳到頁面 y 座標，當 $page 有設定才有效
     * @param  int $style 樣式，可用 Outline::ITALIC (=1) 與 Outline::BOLD (=2) 作為 Flag 設定
     * @param  string|null $color 6 位 hex 字串，代表 RRGGBB
     * @return Outline 這個物件也提供一個 addOutline 方法，以實現多層結構書籤的功能
     */
    public function addOutline($title, $page = null, $y = 0, $style = 0, $color = null)
    {
        $tmp = new Outline;
        $tmp->title = $title;
        $tmp->page = $page;
        $tmp->y = PageMetrics::getPt($y);
        $tmp->style = $style;
        $tmp->color = $color;
        $this->children[] = $tmp;
        return $tmp;
    }

    /**
     * 在寫入前預先保留 id
     * 並設定好相關的連結: parentId, prevId, nextId, firstId, lastId, count
     *
     * @param  StreamWriter $writer
     * @return void
     */
    public function prepareId(StreamWriter $writer)
    {
        $this->currentId = $writer->preserveId();
        $n = count($this->children);
        if ($n > 0) {
            $tmp = [$this->children[0]->prepareId($writer)];
            $this->children[0]->parentId = $this->currentId;
            for ($i = 1; $i < $n; ++$i) {
                $item = $this->children[$i];
                $tmp[] = $item->prepareId($writer);
                $item->parentId = $this->currentId;
                $item->prevId = $tmp[$i - 1];
                $this->children[$i - 1]->nextId = $tmp[$i];
            }
            $this->firstId = $this->children[0]->currentId;
            $this->lastId = $this->children[$n - 1]->currentId;
            $this->count = $this->lastId - $this->firstId + 1; // 因為 ID 連續
        }
        return $this->currentId;
    }

    /**
     * 寫入 Outline
     *
     * @param  StreamWriter $writer
     * @param  array $pageIdArray 各頁面的 id，用來轉換第 k 頁對應到的 id
     * @param  array $pages 透過 metrics 屬性可以取得頁面長寬，用來計算 y 的位置
     * @return void
     */
    public function writeOutlineDict(StreamWriter $writer, $pageIdArray, $pages)
    {
        $dict = [];
        if ($this->title === null) {
            $dict[] = "/Type /Outlines";
        } else {
            $title = 'FEFF' . bin2hex(mb_convert_encoding($this->title, 'UTF-16BE', 'UTF-8'));
            $dict[] = "/Title <$title>";
        }
        if ($this->parentId !== null) {
            $dict[] = "/Parent {$this->parentId} 0 R";
        }
        if ($this->prevId !== null) {
            $dict[] = "/Prev {$this->prevId} 0 R";
        }
        if ($this->nextId !== null) {
            $dict[] = "/Next {$this->nextId} 0 R";
        }
        if ($this->firstId !== null) {
            $dict[] = "/First {$this->firstId} 0 R";
        }
        if ($this->lastId !== null) {
            $dict[] = "/Last {$this->lastId} 0 R";
        }
        if ($this->count !== null) {
            $dict[] = "/Count {$this->count}";
        }
        if ($this->page !== null) {
            $pageId = $pageIdArray[$this->page - 1];
            $y = $pages[$this->page - 1]['metrics']->height - $this->y;
            $dict[] = "/Dest [$pageId 0 R /XYZ null $y null]";
        }
        if ($this->color !== null) {
            $color = $this->convertRGBValue($this->color);
            $dict[] = "/C [$color]";
        }
        if ($this->style !== 0) {
            $dict[] = "/F {$this->style}";
        }
        $writer->writeDict(implode("\n", $dict), $this->currentId);
        foreach ($this->children as $item) {
            $item->writeOutlineDict($writer, $pageIdArray, $pages);
        }
    }

    /**
     * 把 16 進位的 RGB 轉換成三個浮點數構成的字串
     * 
     * @param string 顏色值，例如 "FFFFFF"
     * @return string 顏色值，例如 "1 0.5 1"
     */
    private function convertRGBValue($colorStr)
    {
        $val = hexdec($colorStr);
        $r = ($val >> 16 & 0xff) / 255;
        $g = ($val >> 8 & 0xff) / 255;
        $b = ($val & 0xff) / 255;
        return "$r $g $b";
    }
}
