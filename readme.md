# PDFWriter

這是一個 2020 年底新開發的 PHP PDF Library

＊注意：這是預覽版本，非正式發布，有些東西可能還會變動＊

## 專案的方向

* 支援全範圍 unicode（現在很多 PHP PDF 函式庫只支援到[基本多文種平面](https://zh.wikipedia.org/wiki/Unicode%E5%AD%97%E7%AC%A6%E5%B9%B3%E9%9D%A2%E6%98%A0%E5%B0%84#%E5%9F%BA%E6%9C%AC%E5%A4%9A%E6%96%87%E7%A7%8D%E5%B9%B3%E9%9D%A2)）
* 至少支援 TTF 與 OTF 字型，或是更多種。（目前 TTF 跟 OTF 都可以使用）
* 當字型缺字時，自動切換為後補字型。這個可以達到英數與中文字採用不同字型的效果。
* 提供擴充的介面，讓其他開發者依需求擴充自己需要的模組。

## 導覽

在架構上，PDFWriter 分為三層：
1. PDFWriter 核心：管理字型、頁面等，並把字型跟頁面提供給 Cntent Module 開發使用。
2. Cntent Module：透過 PDFWriter 提供的物件，負責產生頁面的內容。
3. 一般使用者：使用 PDFWriter + Cntent Module(s) 產生 PDF

關於如何建立 Cntent Module 可以參考：[如何加入新的模組](doc/module.md)。

這邊只介紹自帶的基本功能：字型、文字、線條（內嵌點陣圖之後會加上去）
如果只是產生報表應該還堪用

PS. 文字、線條的功能都是由 Cntent Module 實現，現階段不會太過深入高級功能的開發，而是透過提供介面給予增加功能的彈性。所以只提供最基礎的功能。

### 安裝

    composer require ren1244/pdfwriter:dev-main

### 使用

以下是最簡單的程式碼，只產生一個空白頁面

    $pdf=new PDFWriter; //建立 PDFWriter 物件
    $pdf->addPage('A4'); //添加空白頁面
    $pdf->output();

透過呼叫 addPage 方法可以不斷地增加新的頁面

※以下均以 `$pdf` 代表建立好的 PDFWriter 物件

#### 頁面

指定新增的頁面大小，可以直接給予長寬

    $pdf->addPage(200, 150); //新增寬 20 cm 高 15 cm 的頁面

或是輸入預先設定的紙張種類

    $pdf->addPage('A4'); //新增一張 A4 大小的頁面

關於紙張的預設值，請參考 src/Config.php 內的 PAGE_SIZE 常數

（src/Config.php 是給使用者自行修改的）

#### 輸出

預設是把 pdf 直接回應給使用者
如果想儲存在伺服器內的檔案
可以給予在 output 時指定一個 file pointer resource

    $fp=fopen('output.pdf', 'wb');
    $pdf->output($fp);
    fclose($fp);

#### 字型

在 PDF 中嵌入字型

    $pdf->font->addFont(字型名稱);

之後隨時可以透過 setFont 切換字型的種類跟字體大小

    $pdf->font->setFont(字型名稱, 字型大小);

如果要使用替代字型的功能，當 A 字型沒有這個字時會自動用 B 字型替代

    $pdf->font->setFont([
        A字型名稱 => A字型大小,
        B字型名稱 => B字型大小,
        ...
    ]);

##### 自訂字型

除了 PDF 預設的 13 種字型（只能輸出英數字元）外，這個專案不預裝任何字型。

想安裝字型，先準備一個字型檔，然後使用 FontLoader 的 loadFile 方法。

    <?php
    use use ren1244\PDFWriter\FontLib\FontLoader;
    $loader = new FontLoader;
    //$fname 是來源字型的檔案路徑
    //$outname 是輸出的名稱，同時也是之後使用這個字型的名稱
    $loader->loadFile($fname, $outname);

如果載入成功，font 資料夾會多出 {outname}.json 與 {outname}.bin 兩個檔案。
未來想移除字型刪除這兩個檔案即可。

另一個安裝字型的方式是使用 scripts 資料夾中的 addFont.php。
這是在 console 環境跑的 script（使用前可能要修正一下 require 的路徑）

    php addFont.php {fname} {outname}

PS. 以上兩種安裝方式，outname 可以省略，此時 outname 會依據字型檔案內的資料自動產生。

#### 文字

注意：使用文字之前必須先設定好字型（參考上面的敘述）

添加文字步驟如下：

1. 設定文字框的位置跟大小（沒設定的話預設是整個頁面）
2. 透過 addText 寫入文字

範例

    //設定文字框的位置跟大小
    $pdf->text->setRect(左上角x, 左上角y, 寬度, 高度);

    //寫入文字，其中起始y也可以設定為 'top' 字串，這會自動貼齊 rect 最上方
    $pdf->text->addText(文字UTF-8字串, 起始x, 起始y);

addText 有可選的第四個參數，這是一個關聯陣列，可以設定「行高」、「英數字是否強迫換行」、「位置調整」
例如下列範例設定行高為1.5倍，且遇到英數字強迫換行

    $pdf->text->addText(文字UTF-8字串, 起始x, 起始y, [
        'lineHeight' => 1.5,
        'wordBreak' => true,
        'adjustPosition' => 5 //微調置中(對應數字鍵)
    ]);

PS. 每頁的文字框是獨立的，不跨頁使用。

#### 向量圖(線條)

可以直接寫 postScript 語言來畫線條

    $pdf->PostscriptGragh->addPath(postScript, 線寬);

例如想畫一條從 (10, 10) 到 (20, 30) 的直線

    $pdf->PostscriptGragh->addPath('10 10 m 20 30 l S', PageMetrics::getUint(1));

PS. 由於 addPath 的單位是預設單位，所以線寬希望是 1 pt 時，可以用 PageMetrics::getUint(1) 把 pt 轉換為 unit 單位。

#### 點陣圖

目前支援的格式有 png 跟 jpeg，其他格式要先轉換一下

加入點陣圖檔案

    $pdf->image->addImage(檔案路徑, x座標, y座標, 寬度(可選), 高度(可選));

或是已經取得檔案內容，則使用 addImageRaw 方法

    $pdf->image->addImageRaw(檔案內容, x座標, y座標, 寬度(可選), 高度(可選));

#### 單位

上面的 text 跟 PostscriptGragh 單位都會是「目前單位」，預設是 mm
在「目前單位」下，臨時要使用 pt 可以用 PageMetrics::Pt 函式轉換

如果想變更單位

    $pdf->unit='cm'; //變更預設單位為 cm

目前允許設定的值為 cm、mm、pt，設定其他值的話都會視為 pt
