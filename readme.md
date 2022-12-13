# PDFWriter

這是一個 2020 年底新開發的 PHP PDF Library

＊注意：這是預覽版本，非正式發布，有些東西可能還會變動＊

## 專案的方向

* 支援全範圍 unicode（現在很多 PHP PDF 函式庫只支援到[基本多文種平面](https://zh.wikipedia.org/wiki/Unicode%E5%AD%97%E7%AC%A6%E5%B9%B3%E9%9D%A2%E6%98%A0%E5%B0%84#%E5%9F%BA%E6%9C%AC%E5%A4%9A%E6%96%87%E7%A7%8D%E5%B9%B3%E9%9D%A2)）
* 至少支援 TTF 與 OTF 字型，或是更多種。（目前 TTF 跟 OTF 都可以使用，不過 otf 檔案會較大）
* 當字型缺字時，自動切換為候補字型。這個可以達到英數與中文字採用不同字型的效果。
* 提供擴充的介面，讓其他開發者依需求擴充自己需要的模組。

## 安裝

```shell
composer require ren1244/pdfwriter:dev-main
```

## 使用

以下是最簡單的程式碼，只產生一個空白頁面

```php
$pdf = new PDFWriter; //建立 PDFWriter 物件
$pdf->addPage('A4'); //添加空白頁面
$pdf->output();
```

透過呼叫 addPage 方法可以不斷地增加新的頁面

※以下均以 `$pdf` 代表建立好的 PDFWriter 物件

### 新增頁面

指定新增的頁面大小，可以直接給予長寬

```php
//新增寬 20 cm 高 15 cm 的頁面
$pdf->addPage(200, 150);
```

或是輸入預先設定的紙張種類，目前可設定的值有：

* A 系列: `"A0"` 到 `"A10"`
* B 系列: `"B0"` 到 `"B10"`
* C 系列: `"C0"` 到 `"C10"`
* JIS-B 系列: `"JIS-B0"` 到 `"JIS-B10"`

```php
//新增一張 A4 大小的頁面，預設是直向
$pdf->addPage('A4');
```

如果希望紙張是橫向的，可以在預設的紙張種類後面加上 `"L"` 或 `"H"`，意思分別是 Landscape 與 Horizontal

```php
//新增一張 A4 大小的頁面，橫向
$pdf->addPage('A4H');
```

### 輸出 PDF

預設是把 pdf 直接回應給使用者
如果想先儲存在伺服器內的檔案
可以給予在 output 時指定一個 file pointer resource

```php
$fp = fopen('output.pdf', 'wb');
$pdf->output($fp);
fclose($fp);
```

### 單位

當涉及到長度的參數時，若沒有特別提及，都是指「目前選用的單位」（後面以「目前單位」簡稱）

預設狀態下，「目前單位」被設定為 `"mm"`，可以設定 `PageMetrics::$unit` 來改變它

允許設定的值為 `"cm"`, `"mm"`, `"pt"`，設定其他值的話都會視為 `"pt"`

```php
// 把「目前單位」設定為 "cm"
PageMetrics::$unit = "cm";
```

如果臨時要把 pt 轉換為「目前單位」

```php
// 把 0.5 pt 轉換為「目前單位」
PageMetrics::getUnit(0.5);
```

如果臨時要把「目前單位」轉換為 pt

```php
// 把 0.25 「目前單位」轉換 pt
PageMetrics::getPt(0.25);
```

### 文字

```php
$pdf = new PDFWriter;
$pdf->addPage('A4');

// 添加字型（此時會自動設定目前字型為 Times-Roman 12pt）
$pdf->font->addFont('Times-Roman');
// 設定字型（Times-Roman 14pt）
$pdf->font->setFont('Times-Roman', 14);
// 設定要寫入的矩形區域: left, top, width, height （單位是「目前單位」，此時是 mm）
$pdf->text->setRect(10, 10, 100, 100);
//寫入文字
$pdf->text->addText('Hello');

$pdf->output();
```

#### 內建字型

上面的範例中的 `$pdf->font->addFont('Times-Roman');` 就是使用內建字型。

目前內建的字型有，包含一般的英數字元

* `Times-Roman`, `Times-Bold`, `Times-Italic`, `Times-BoldItalic`
* `Helvetica`, `Helvetica-Bold`, `Helvetica-Oblique`, `Helvetica-BoldOblique`
* `Courier`, `Courier-Bold`, `Courier-Oblique`, `Courier-BoldOblique`

#### 自訂字型

透過 script/addFont.php 添加字型（產生的結果放在 font 資料夾）

```shell
php script/addFont.php {filename} [{outname}]
```

* `filename`: 字型檔
* `outname`: 提供 `addFont` 與 `setFont` 使用的名稱，若省略會自動根據字型檔產生

#### 字型替代

這個功能是在字型缺字的狀況下，允許自動切換替代的字型。

透過這個功能，可以讓我們在英數字自動使用 `Times-Roman`，而遇到中文自動使用 `Noto-Sans`。

範例如下

```php
$pdf->font->setFont([
    'Times-Roman' => 12, // 優先使用 Times-Roman 12pt
    'Noto-Sans' => 14,   // 如果缺字，用 Noto-Sans 14pt 替代
]);
```

#### 文字的排版

* 文字必須在由 `setRect` 指定的文字框內，如果空間不夠會截斷
* 目前不支援跨頁，也就是每頁的狀態是獨立的

`addText` 的第二個參數是一個選擇性的參數，為一個關聯陣列，可以有以下選擇

| KEY | 值的資料類型 | 預設值 | 說明 |
|---|---|---|---|
|lineHeight |number|1.2| 行高，相對於該行字型大小，預設值為1.2 |
|wordBreak|bool|false| 英數是否強制換行|
|color|string\|false|false| 文字顏色，RRGGBB，例如 "FFCC00"<br>若為 false 則視環境設定而不同|
|textAlign|string|"left"| 多行文字對齊，可能的值有："left", "center", "right"|
|cellAlign|integer|7| 文字要對齊文字框的何處，允許的數值是 1~9，對應數字鍵的位置|
|underline|number|0| 底線寬，單位是 pt，如果為 0 則不添加底線|

### 圖形

#### 向量圖(線條)

目前只能用 postScript 語言來畫線條（跟 svg 有點像）

```php
/**
 * 畫一條從 (10, 10) 到 (20, 30) 的直線，線寬是 1 pt
 * 這邊單位都是「目前單位」，所以指定線寬時使用 PageMetrics::getUnit
 */
$pdf->postscriptGragh->addPath('10 10 m 20 30 l S', PageMetrics::getUnit(1));
```

#### 點陣圖

目前支援的格式有 `png` 跟 `jpeg`，其他格式要先轉換一下

```php
// 把 jpg 圖片畫在 ($x, $y) 的位置，長寬依照原本的尺寸
$pdf->image->addImage("example.jpg", $x, $y);

// 把 png 圖片畫在 ($x, $y) 的位置，並指定長寬
$pdf->image->addImage("example.png", $x, $y, $width, $height);

/** 
 * 把 png 圖片畫在 ($x, $y) 的位置
 * 當長寬只指定一個的時候，另一個請設定為 false，此時會等比例縮放
 */
$pdf->image->addImage("example.png", $x, $y, false, $height);
```

若是已經取得圖檔內容，可以使用 `addImageRaw` 方法，使用方法與 `addImage` 相同。

```php
$imageContent = file_get_contents("example.png");
$pdf->image->addImageRaw($imageContent, $x, $y, $width, $height);
```

### 書籤

#### 簡易書籤

```php
// 建立一個書籤，當點擊時會跳到第 2 頁，從頂部往下算 10 unit 的位置
$pdf->addOutline("第一章", 2, 10);

/**
 * 建立一個書籤，當點擊時會跳到第 3 頁，從頂部往下算 10 unit 的位置
 * 樣式被設定為「斜體 + 粗體」，顏色為紅色（#FF0000）
 */
$pdf->addOutline("第二章", 3, 10, Outline::ITALIC | Outline::BOLD, "FF0000");
```

#### 多層級書籤

```php
// 紀錄 addOutline 回傳的值，這是一個 Outline 物件
$chapter1 = $pdf->addOutline("第一章", 2, 10);

// Outline 物件本身也可以建立書籤，參數最多可以有 5 個，說明參考「簡易書籤」的部分
$chapter1->addOutline("第一節", 2, 15);

// 若不指定第幾頁，則點擊後無任何效果，可以單純作為資料夾
$other = $pdf->addOutline("其他");
$other->addOutline("參考文件", 10, 10);
```

### 加密

目前支援 Aes256、Aes128、Rc4_128 三種加密，可參考 `examples/ex3_encryption.php`

PS. 目前書籤功能在加密狀態下會有些問題，待修正

## 擴充功能

在架構上，PDFWriter 分為三層：
1. PDFWriter 核心：管理字型、頁面等，並把字型跟頁面提供給 Cntent Module 開發使用。
2. Cntent Module：透過 PDFWriter 提供的物件，負責產生頁面的內容。
3. 一般使用者：使用 PDFWriter + Cntent Module(s) 產生 PDF

關於如何建立 Cntent Module 可以參考：[如何加入新的模組](doc/module.md)。

這邊只介紹自帶的基本功能：字型、文字、線條、內嵌點陣圖
如果只是產生報表應該還堪用

PS. 文字、線條的功能都是由 Cntent Module 實現，現階段不會太過深入高級功能的開發，而是透過提供介面給予增加功能的彈性。所以只提供最基礎的功能。