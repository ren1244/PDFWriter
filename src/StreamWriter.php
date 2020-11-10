<?php
namespace ren1244\PDFWriter;

/**
 * 提供寫入到檔案等
 */
class StreamWriter
{
    const GZIP=1;
    const FLATEDECODE=2;
    const COMPRESS=3;
    private $ofp;
    private $closeFlag;
    private $idCount=0;
    private $preserveIdTable=[];
    private $posTable=[];
    private $pos=0;
    
    public function __construct($fp=false)
    {
        if($fp) {
            $this->ofp=$fp;
            $this->closeFlag=false;
        } else {
            $this->ofp=fopen('php://output', 'wb');
            $this->closeFlag=true;
        }
    }

    public function __destruct()
    {
        if($this->closeFlag) {
            fclose($this->ofp);
        }
    }

    public function preserveId()
    {
        $id=++$this->idCount;
        $this->preserveIdTable[$id]=true;
        return $id;
    }

    public function writeDict($content, $id=false)
    {
        $this->initId($id);
        $dictContent="$id 0 obj\n<<\n$content\n>>\nendobj\n";
        fwrite($this->ofp, $dictContent);
        $this->posTable[$id]=$this->pos;
        $this->pos+=strlen($dictContent);
        return $id;
    }

    public function writeFinish($rootId)
    {
        $xrefPos=$this->pos;
        $n=$this->idCount;
        $this->writeLine(sprintf("xref\n0 %d\n0000000000 65535 f ", $n+1));
        for($i=1;$i<=$n;++$i) {
            $this->writeLine(sprintf("%010d 00000 n ", $this->posTable[$i]));
        }
        $this->writeLine('trailer');
        $this->writeLine('<<');
        $this->writeLine(sprintf('/Size %d', $n+1));
        $this->writeLine(sprintf('/Root %d 0 R', $rootId));
        $this->writeLine('>>');
        $this->writeLine('startxref');
        $this->writeLine($xrefPos);
        $this->writeLine('%%EOF');
    }

    public function writeStream($content, $compressFlag=false, $entries=[], $id=false)
    {
        $this->initId($id);
        if($compressFlag&StreamWriter::GZIP) {
            $streamContent=gzcompress($content, Config::GZIP_LEVEL);
        } else {
            $streamContent=$content;
        }
        if($compressFlag&StreamWriter::FLATEDECODE) {
            $entries['Filter']='/FlateDecode';
        }
        $entries['Length']=strlen($streamContent);
        $dictContent=[];
        foreach($entries as $key=>$val) {
            $dictContent[]="/$key $val";
        }
        $dictContent=implode("\n", $dictContent);
        $rawContent="$id 0 obj\n<<\n$dictContent\n>>\nstream\n$streamContent\nendstream\nendobj\n";
        fwrite($this->ofp, $rawContent);
        $this->posTable[$id]=$this->pos;
        $this->pos+=strlen($rawContent);
        return $id;
    }

    public function writeLine($content)
    {
        fwrite($this->ofp, "$content\n");
        $this->pos+=strlen($content)+1;
    }

    private function initId(&$id)
    {
        if($id===false) {
            $id=++$this->idCount;
        } else{
            if(isset($this->preserveIdTable[$id])) {
                unset($this->preserveIdTable[$id]);
            } else {
                throw new \Exception('ID 必須先保留');
            }
        }
    }
}