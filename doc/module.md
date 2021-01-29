# 模組

每當我們使用 $pdf->{模組名稱}->模組方法 時，其實是直接取得一個模組。例如先前導覽中
$pdf->test->addText 實際上是執行 Text 類別內的 addText 方法。

模組名稱不一定會跟類別名稱相同，而是由 Config.php 的 Modules 常數來定義

模組物件在各頁面之間都是獨立的，兩個頁面不會使用到相同的模組物件實體

有一個比較特殊的模組：$pdf->font，這是核心內定的，他對應到的是 FontLib\FontController 物件，而且這個物件是跨頁面共用的。

除了 $pdf->font 之外，其他的模組都屬於內容模組。例如 text、postscriptGragh

## 建立內容模組

內容模組負責處理當前頁面內容的產生，這是一個 class ，遵循以下規則。

1. 可以有一個 constructor，constructor 的參數必須有 type hint 作為依賴注入的參考。能夠被依賴注入的類型有：
    1. FontController：提供字型資訊，例如每個字的寬度是多少等。
    2. PageMetrics：提供當前頁面的尺寸，並管理當前頁面內容的資料。
    3. ImageResource：註冊並取得點陣圖的資源ID。
    4. 其他內容模組類別
2. 可以有一個 write 方法，具有兩個參數：
    1. $streamWriter：這是一個 StreamWriter 物件。
    2. $datas：要被轉換成 content stream 的資料陣列。    
    write 方法可以使用 $streamWriter->writeStream 將 content stream 寫到 PDF。 content stream 由 [PDF32000_2008](https://www.adobe.com/content/dam/acom/en/devnet/pdf/pdfs/PDF32000_2008.pdf) 定義。如果這個內容模組只是重新包裝其他內容模組，可以省略 write 方法或是回傳 false。
    3. 回傳值為：
        * integer：由 $streamWriter->writeStream 產生的 content stream id。
        * array：如果需要回傳多個 id 的話，可以用陣列包起來。
        * false：表示這個 class 沒有自己寫入 content stream。
3. 除了 construct 跟 write 方法之外的其他 public 方法，都可以透過 $pdf->{模組名稱}->模組方法 給使用者呼叫。注意：通常會呼叫 $pageMetrics->pushData($this, 要推送的資料)，這些要推送的資料會當作 write 方法的第二個參數傳入。
4. 開發時要注意使用者在同一頁會多次呼叫，所以一般都會有個 buffer 儲存每次要寫入的資料，直到 write 方法被 PDFWriter 呼叫才真正寫出來。

## 註冊模組

必須讓 PDFWriter 知道我們寫了一個模組，有以下兩種方式：

1. 修改 Config.php 的 Modules 常數的內容。這是一個陣列，由「模組名稱」映射到「類別名稱」。
2. 使用者建立 PDFWriter 物件時，可以傳入參數：「模組名稱」映射到「類別名稱」的陣列。這個方式更適合把 PDFWriter 核心與其他功能分離開。

## 使用文字與字型

### 用 constructor 取得 FontController

在模組物件的 constructor 中，可以透過參數取得 FontController 物件
(namespace 的問題這邊就不贅述了)

    public function __construct(FontController $ftCtrl, PageMetrics $mtx)
    {
        //把 $ftCtrl 記錄下來
        $this->ftCtrl=$ftCtrl;

        //把 PageMetrics 物件紀錄下來
        $this->mtx=$mtx;
    }

### 開發 API

任何一個除了 construct 跟 write 之外的其他 public function 都是可以被使用者呼叫的 API
在 public function 我們可以：

1. 使用 $this->ftCtrl->getMtx() 可以取得 ascent 跟 descent 資訊，用來計算行的高度。（ascent 代表基線以上，descent代表基線以下）

2. 使用 $this->ftCtrl->getWidth($unicode) 可以取得某個 $unicode 的字寬，同時這代表告訴 FontController 這個字會被使用到，之後嵌入字型要包含這個字。

3. 透過  $this->mtx->pushData($this, $someData) 把「寫入 content stream 要參考的資料」推送到 pdfwriter 管理的 queue 中，之後會作為 write 的第二個參數使用。

### 寫入 content stream

在 write 方法中，可以透過 $this->ftCtrl->getText($str) 將 $str 轉換為 PDF 內使用的十六進位字串。例如：

    public function write($streamWriter, $datas)
    {
        //把 $datas 的每筆資料轉換成 content stream 內容
        $arr=array_map(function($str){
            //轉換為 pdf 使用的 hex code，注意這邊的 str 必須是 UTF-16BE
            $hexStr=$this->ftCtrl->getText($str);

            /**
            * 組 content stream，細節請參考 https://www.adobe.com/content/dam/acom/en/devnet/pdf/pdfs/PDF32000_2008.pdf
            * BT 跟 ET 代表 Begin Text 跟 End Text
            * 100 100 Td 代表移動到 100,100 的位置
            * <...> Tj 代表把文字寫出來，裡面都是 16 進位字串
            * 至於 16 進位字串的產生已經由 FontController 幫我們做好了
            */
            return "BT 100 100 Td <$hexStr> Tj ET";
        }, $datas);

        //寫入 pdf 並把 id 回傳
        return $streamWriter->writeStream(implode(' ', $arr)));
    }

或是分成多段寫入，然後以陣列回傳所有 id

    public function write($streamWriter, $datas)
    {
        //把 $datas 的每筆資料轉換成 content stream id
        return array_map(function($str){
            //轉換為 pdf 使用的 hex code，注意這邊的 str 必須是 UTF-16BE
            $hexStr=$this->ftCtrl->getText($str);

            /**
            * 組 content stream，細節請參考 https://www.adobe.com/content/dam/acom/en/devnet/pdf/pdfs/PDF32000_2008.pdf
            * BT 跟 ET 代表 Begin Text 跟 End Text
            * 100 100 Td 代表移動到 100,100 的位置
            * <...> Tj 代表把文字寫出來，裡面都是 16 進位字串
            * 至於 16 進位字串的產生已經由 FontController 幫我們做好了
            */
            return $streamWriter->writeStream("BT 100 100 Td <$hexStr> Tj ET");
        }, $datas);
    }




