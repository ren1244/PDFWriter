<?php
namespace ren1244\PDFWriter;

class PDFWriter
{
    /**
     * Permission Consts
     */
    const PERM_PRINT = 0x04; //R=2; R>=3: 根據 bit 12 是否低品質列印
    const PERM_MODIFY = 0x08; //修改內容，但 bit 6; 9; 11 的操作另外由其設定
    const PERM_CPOY = 0x10; //R>=2:複製內容，R>=3: 可複製，但 bit 10 有另外設定
    const PERM_ANNOT_FORMS = 0x20; //增改註解、填寫表單，若 bit4 有設定，再允許增改表單
    const PERM_FILL_FORMS = 0x100; // R>=3: 允許填寫表單（bit6 沒設定也可以填寫）
    const PERM_EXTRACT = 0x200; // R>=3: 複製內容
    const PERM_ASSEMBLE = 0x400; // R>=3: 重組 PDF（bit4 沒設定也可以重組）
    const PERM_PRINT_HIGHRES = 0x800; // R>=3: 
    const PERM_ALL = 0xf3c;

    private $writer;
    
    private $pages=[]; //{width:num, height:num, contents:[int(index of $this->contents),...]}
    private $resourceEntities=[]; //resource 物件實體, key=>entity

    private $catalogId;
    private $pageTreeId;
    private $resourceId; //resource Dict's id

    private $currentPageIdx;

    private $contentModules;
    private $moduleClassToKey;

    private $resourceModules;
    private $resourceClassToKey;

    private $encryptionObject = null;
    private $outlineObject = null;

    /**
     * 建立 PDFWriter 物件
     * 
     * @param array $withModules 額外載入的模組，格式為 [模組名=>class 名稱, ...]
     * @return void
     */
    public function __construct($withModules=[], $withResources=[])
    {
        $this->writer=new StreamWriter;
        $this->catalogId=$this->writer->preserveId();
        $this->pageTreeId=$this->writer->preserveId();
        $this->resourceId=$this->writer->preserveId();
        $this->contentModules=Config::Modules+$withModules;
        $this->moduleClassToKey=array_flip($this->contentModules);
        $this->moduleClassToKey[PageMetrics::class]='metrics';
        $this->resourceModules=Config::Resources+$withResources;
        $this->resourceClassToKey=array_flip($this->resourceModules);
    }

    /**
     * 取得模組物件
     * 
     * @param string $name 模組名稱
     * @return object 模組物件
     */
    public function __get($name)
    {
        //Resource
        if(isset($this->resourceModules[$name])) {
            if(!isset($this->resourceEntities[$name])) {
                $this->resourceEntities[$name] = new $this->resourceModules[$name];
            }
            return $this->resourceEntities[$name];
        }
        if(empty($this->pages)) {
            throw new \Exception('No page added');
        }
        //Content
        if(isset($this->contentModules[$name])) {
            $curPage = &$this->pages[$this->currentPageIdx];
            $className = $this->contentModules[$name];
            if(!isset($curPage[$name])) {
                $this->createContentModuleEntity($className, $curPage);
            }
            return $curPage[$name];
        }
        throw new \Exception('Content module not found');
    }

    /**
     * 新增一頁
     * 
     * @param int|string $widthOrName 如果是整數，代表寬度，單位為目前設定的單位。
     *                                如果是字串，依據預設的紙張類型設定寬高。
     * @param int $height 高度，單位為目前設定的單位
     * @return void
     */
    public function addPage($widthOrName, $height=false)
    {
        if(gettype($widthOrName)==='string') {
            $s1 = substr($widthOrName, 0, -1);
            $s2 = substr($widthOrName, -1);
            if(isset(Config::PAGE_SIZE[$widthOrName])) {
                $arr = Config::PAGE_SIZE[$widthOrName];
            } elseif(isset(Config::PAGE_SIZE[$s1])) {
                $arr = Config::PAGE_SIZE[$s1];
            } else {
                throw 'no page named '.$widthOrName;
            }
            if($s2==='L'||$s2==='H'){
                $width=$arr[1];
                $height=$arr[0];
            } else {
                $width=$arr[0];
                $height=$arr[1];
            }
        } else {
            $height=PageMetrics::getPt($height);
            $width=PageMetrics::getPt($widthOrName);
        }
        $mtx=new PageMetrics($width, $height);
        $curPage=['metrics'=>$mtx];
        $this->pages[]=$curPage;
        $this->currentPageIdx=array_key_last($this->pages);
    }
    
    /**
     * 設定此 PDF 為加密
     *
     * @param  string $className 加密的 class
     *                           在 \ren1244\PDFWriter\Encryption\ 中有
     *                           Rc4_128、Aes128、Aes256
     * @param  string $userPassword 使用者密碼（只有 AES256 允許 unicode）
     * @param  string $ownerPassword 所有者密碼（只有 AES256 允許 unicode）
     * @param  int    $permisionFlag 權限 flag，參考 \ren1244\PDFWriter::PERM_*
     * @return void
     */
    public function setEncryption($className, $userPassword, $ownerPassword, $permisionFlag)
    {
        $docId = hex2bin($this->writer->getDocId());
        $this->encryptionObject = new $className($userPassword, $ownerPassword, $permisionFlag, $docId);
        $this->writer->setEncryptionObject($this->encryptionObject);
    }
    
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
    public function addOutline($title, $page = null, $y = 0, $style = 0, $color = null) {
        if($this->outlineObject===null) {
            $this->outlineObject = new Outline();
        }
        return $this->outlineObject->addOutline($title, $page, $y, $style, $color);
    }

    /**
     * 輸出 PDF
     * 
     * @param object $fp file pointer object
     * @return void
     */
    public function output($fp=false)
    {
        if(is_null(array_key_last($this->pages))) {
            $this->addPage('A4');
        }
        //資源預處理
        foreach($this->resourceEntities as $res) {
            $resArr[]=$res->preprocess();
        }
        $pdf=$this->writer;
        $pdf->setOutputTarget($fp);
        if($fp===false) {
            header('Content-Type: application/pdf');
        }
        //prepare Id
        $nPages=count($this->pages);
        $pageIds=$this->preserveIdArray($nPages);
        //hrader
        $pdf->writeLine($this->encryptionObject instanceof Encryption\EncryptionInterface ? '%PDF-1.7' : '%PDF-1.4');
        $pdf->writeLine('%§§');
        //catalog
        if($this->outlineObject===null) {
            $pdf->writeDict("/Type /Catalog\n/Pages $this->pageTreeId 0 R", $this->catalogId);
        } else {
            $outlineId=$this->outlineObject->prepareId($this->writer);
            $pdf->writeDict("/Type /Catalog\n/Pages $this->pageTreeId 0 R\n/Outlines $outlineId 0 R\n/PageMode /UseOutlines", $this->catalogId);
        }
        //root page tree
        $kids=implode(' 0 R ', $pageIds);
        $pdf->writeDict("/Type /Pages\n/Kids [$kids 0 R]\n/Count $nPages", $this->pageTreeId);
        //outline
        if($this->outlineObject!==null) {
            $this->outlineObject->writeOutlineDict($this->writer, $pageIds, $this->pages);
        }
        //pages
        foreach($this->pages as $i=>&$page) {
            $tmpIds=[];
            $queue=&$page['metrics']->dataQueue;
            $nData=count($queue);
            if($nData>0){
                $dataBeginIdx=0;
                $curModule=$queue[0][0];
                for($k=1;$k<$nData;++$k) {
                    if($queue[$k][0]!==$curModule) {
                        // 從 dataBeginIdx ~ k-1 都是 curModule
                        $csId=$curModule->write(
                            $pdf,
                            array_column(array_slice($queue, $dataBeginIdx, $k-$dataBeginIdx), 1)
                        );
                        if($csId!==false) {
                            if(gettype($csId)==='array') {
                                $tmpIds=array_merge($tmpIds, $csId);
                            } else {
                                $tmpIds[]=$csId;
                            }
                        }
                        $curModule=$queue[$k][0];
                        $dataBeginIdx=$k;
                    }
                }
                $csId=$curModule->write(
                    $pdf,
                    array_column(array_slice($queue, $dataBeginIdx, $nData-$dataBeginIdx), 1)
                );
                if($csId!==false) {
                    if(gettype($csId)==='array') {
                        $tmpIds=array_merge($tmpIds, $csId);
                    } else {
                        $tmpIds[]=$csId;
                    }
                }
            }
            if(($tmpIds=implode(' 0 R ', $tmpIds))!=='') {
                $tmpIds.=' 0 R';
            }
            $tmpIds="\n/Contents [$tmpIds]";
            $pageWidth=$page['metrics']->width;
            $pageHeight=$page['metrics']->height;
            $pdf->writeDict("/Type /Page\n/Parent $this->pageTreeId 0 R\n/Resources $this->resourceId 0 R$tmpIds\n/MediaBox [0 0 $pageWidth $pageHeight]", $pageIds[$i]);
        }
        //resources
        $resArr=[];
        foreach($this->resourceEntities as $res) {
            $tmp=$res->write($pdf);
            if($tmp===false) {
                continue;
            }
            if(isset($resArr[$tmp[0]])) {
                $resArr[$tmp[0]][]=$tmp[1];
            } else{
                $resArr[$tmp[0]]=[$tmp[1]];
            }
        }
        foreach($resArr as $k=>$v) {
            $resArr[$k] = '/'.$k.' << '.implode(' ', $v).' >>';
        }
        $pdf->writeDict(implode("\n", $resArr), $this->resourceId);
        if($this->encryptionObject instanceof Encryption\EncryptionInterface) {
            $encIdObj = $pdf->writeDict($this->encryptionObject->getEncryptionDict());
        } else {
            $encIdObj = null;
        }
        //finish
        $pdf->writeFinish($this->catalogId, $encIdObj);
    }

    /**
     * 預留 pdf obj id 供之後使用
     * 
     * @param int $n 要保留的個數
     * @return array 已保留的 pdf obj id 陣列
     */
    private function preserveIdArray($n)
    {
        $arr=[];
        for($i=0;$i<$n;++$i) {
            $arr[]=$this->writer->preserveId();
        }
        return $arr;
    }

    /**
     * 在某個頁面上建立 Content Module 物件
     * 
     * @param string $className 類別名稱
     * @param array &$page 頁面相關的資訊
     * @return void
     */
    private function createContentModuleEntity($className, &$page)
    {
        $reflector=new \ReflectionClass($className);
        $refConstructor=$reflector->getConstructor();
        if(is_null($refConstructor)) {
            $objInstance=$reflector->newInstanceWithoutConstructor();
        } else {
            $argList=[];
            $params=$refConstructor->getParameters();
            $n=count($params);
            for($i=0; $i<$n; ++$i) {
                $refType=$params[$i]->getType();
                if(!$refType){
                    throw new \Exception('Module constructor has no type hint');
                }
                if($refType instanceof \ReflectionUnionType) {
                    throw new \Exception('Module constructor has union type hint');
                }
                $depName=$refType->getName();
                if(isset($this->resourceClassToKey[$depName])) {
                    $depName=$this->resourceClassToKey[$depName];
                    $argList[]=$this->$depName;
                    continue;
                }
                $depKey=$this->moduleClassToKey[$depName];
                if(isset($page[$depKey])) {
                    $argList[]=$page[$depKey];
                } elseif(isset($this->contentModules[$depKey])) {
                    $page[$depKey]=false; //避免 content module 循環相依
                    $this->createContentModuleEntity($depName, $page);
                    $argList[]=$page[$depKey];
                } else {
                    throw new \Exception('Module '.$className.' initialization fail');
                }
            }
            $objInstance=$reflector->newInstanceArgs($argList);
        }
        $page[$this->moduleClassToKey[$className]]=$objInstance;
    }
}
