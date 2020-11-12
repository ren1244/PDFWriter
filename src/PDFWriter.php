<?php
namespace ren1244\PDFWriter;

class PDFWriter
{
    private $writer;
    private $ftCtrl;
    private $autoHeader;
    
    private $pages=[]; //{width:num, height:num, contents:[int(index of $this->contents),...]}
    private $contents=[]; //stream contents

    private $catalogId;
    private $pageTreeId;
    private $resourceId;

    private $currentPageIdx;

    private $contentModules;
    private $moduleClassToKey;

    public function __construct($fp=false, $withModules=[])
    {
        $this->writer=new StreamWriter($fp);
        $this->ftCtrl=new FontController;
        $this->catalogId=$this->writer->preserveId();
        $this->pageTreeId=$this->writer->preserveId();
        $this->resourceId=$this->writer->preserveId();
        $this->contentModules=Config::Modules+$withModules;
        $this->moduleClassToKey=array_flip($this->contentModules);
        $this->moduleClassToKey[PageMetrics::class]='metrics';
        $this->autoHeader=$fp===false?true:false;
    }

    public function __get($name)
    {
        if($name==='font') {
            return $this->ftCtrl;
        }
        if(empty($this->pages)) {
            throw new \Exception('No page added');
        }
        if(isset($this->contentModules[$name])) {
            return $this->pages[$this->currentPageIdx][$name];
        }
        throw new \Exception('Content module not found');
    }

    public function addPage($width, $height=false)
    {
        if($height===false) {
            $arr=Config::PAGE_SIZE[$width];
            $width=$arr[0];
            $height=$arr[1];
        } else {
            $height=PageMetrics::getPt($height);
            $width=PageMetrics::getPt($width);
        }
        $mtx=new PageMetrics($width, $height);
        $curPage=['metrics'=>$mtx, 'contents'=>[]];
        foreach($this->contentModules as $key=>$className) {
            if(!isset($curPage[$key])) {
                $this->createContentModuleEntity($className, $curPage);
            }
        }
        $this->pages[]=$curPage;
        $this->currentPageIdx=array_key_last($this->pages);
        $this->pages[$this->currentPageIdx]['text']->setRect([0,0,$width,$height],$width,$height);
    }

    public function addContent($content)
    {
        if(is_null(array_key_last($this->pages))) {
            $this->addPage('A4');
        }
        $this->contents[]=$content;
        $this->pages[$this->currentPageIdx]['contents'][]=
            array_key_last($this->contents);
    }

    public function output()
    {
        if($this->autoHeader) {
            header('Content-Type: application/pdf');
        }
        if(is_null(array_key_last($this->pages))) {
            $this->addPage('A4');
        }
        $this->ftCtrl->subset();
        $pdf=$this->writer;
        //hrader
        $pdf->writeLine('%PDF-1.4');
        $pdf->writeLine('%'.hex2bin('B6EABAA1'));
        //catalog
        $pdf->writeDict("/Type /Catalog\n/Pages $this->pageTreeId 0 R", $this->catalogId);
        //root page tree
        $nPages=count($this->pages);
        $pageIds=$this->preserveIdArray($nPages);
        $kids=implode(' 0 R ', $pageIds);
        $pdf->writeDict("/Type /Pages\n/Kids [$kids 0 R]\n/Count $nPages", $this->pageTreeId);
        //pages
        $nContents=count($this->contents);
        $contentIds=$this->preserveIdArray($nContents);
        foreach($this->pages as $i=>&$page) {
            $cidList=$page['contents'];
            $tmpIds=[];
            if(count($cidList)>0) {
                foreach($cidList as $x) {
                    $tmpIds[]=$contentIds[$x];
                }
            }
            foreach($this->contentModules as $key=>$className) {
                $csId=$page[$key]->write($pdf);
                if($csId!==false) {
                    $tmpIds[]=$csId;
                }
            }
            $tmpIds=implode(' 0 R ', $tmpIds);
            $tmpIds="\n/Contents [$tmpIds 0 R]";
            $pageWidth=$page['metrics']->width;
            $pageHeight=$page['metrics']->height;
            $pdf->writeDict("/Type /Page\n/Parent $this->pageTreeId 0 R\n/Resources $this->resourceId 0 R$tmpIds\n/MediaBox [0 0 $pageWidth $pageHeight]", $pageIds[$i]);
            
        }
        //content streams
        foreach($this->contents as $idx=>$contentStreamData) {
            $id=$contentIds[$idx];
            $pdf->writeStream($contentStreamData, false/* StreamWriter::COMPRESS*/, [], $id);
        }
        //resources
        $ftStr=$this->ftCtrl->write($pdf);
        $pdf->writeDict("$ftStr", $this->resourceId);
        //finish
        $pdf->writeFinish($this->catalogId);
    }

    private function preserveIdArray($n)
    {
        $arr=[];
        for($i=0;$i<$n;++$i) {
            $arr[]=$this->writer->preserveId();
        }
        return $arr;
    }

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
                if($depName===FontController::class) {
                    $argList[]=$this->ftCtrl;
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