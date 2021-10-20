<?php
namespace ren1244\PDFWriter;

class PDFWriter
{
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

    /**
     * 建立 PDFWriter 物件
     * 
     * @param array $withModules 額外載入的模組，格式為 [模組名=>class 名稱, ...]
     * @return void
     */
    public function __construct($withModules=[])
    {
        $this->writer=new StreamWriter;
        $this->catalogId=$this->writer->preserveId();
        $this->pageTreeId=$this->writer->preserveId();
        $this->resourceId=$this->writer->preserveId();
        $this->contentModules=Config::Modules+$withModules;
        $this->moduleClassToKey=array_flip($this->contentModules);
        $this->moduleClassToKey[PageMetrics::class]='metrics';
        $this->resourceModules=Config::Resources;
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
        if($height===false) {
            $arr=Config::PAGE_SIZE[$widthOrName];
            $width=$arr[0];
            $height=$arr[1];
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
        //hrader
        $pdf->writeLine('%PDF-1.4');
        $pdf->writeLine('%§§');
        //catalog
        $pdf->writeDict("/Type /Catalog\n/Pages $this->pageTreeId 0 R", $this->catalogId);
        //root page tree
        $nPages=count($this->pages);
        $pageIds=$this->preserveIdArray($nPages);
        $kids=implode(' 0 R ', $pageIds);
        $pdf->writeDict("/Type /Pages\n/Kids [$kids 0 R]\n/Count $nPages", $this->pageTreeId);
        //pages
        foreach($this->pages as $i=>&$page) {
            $tmpIds=[];
            $queue=&$page['metrics']->dataQueue;
            //var_dump($queue);
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
        //finish
        $pdf->writeFinish($this->catalogId);
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
                if(!$params[$i]->hasType()){
                    throw new \Exception('Module constructor has no type hint');
                }
                $depName=$params[$i]->getClass()->getName();
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