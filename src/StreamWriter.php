<?php
namespace ren1244\PDFWriter;

use ren1244\PDFWriter\Encryption\EncryptionInterface;

/**
 * 刻出 pdf 最基本工具
 * 這裡提供寫入 Dict、Stream 等函式
 * 並自動產生 xref 跟 trailer
 */
class StreamWriter
{
    //以下三個 const 用於 writeStream 的 flag
    const GZIP=1; //使用 gzcompress 壓縮資料，但不管 Filter 項目
    const FLATEDECODE=2; //自動添加 FlateDecode Filter，但不管資料是不是真的有壓所
    const COMPRESS=3; //使用 gzcompress 壓縮資料，並自動添加 FlateDecode Filter

    private $ofp; //輸出的目標
    private $closeFlag=false;
    private $idCount=0;
    private $preserveIdTable=[];
    private $posTable=[];
    private $pos=0;
    private $encryptionObject=null;
    private $docId;
    
    /**
     * 建立 StreamWriter 物件
     * 
     * @param object|false $fp 輸出目標的 file point resource
     *                     如果沒給，會自動用 php://output 為輸出目標
     * @return void
     */
    public function __construct($fp=false)
    {
        $this->ofp=$fp;
        $this->docId = bin2hex(random_bytes(16));
    }

    public function __destruct()
    {
        if($this->closeFlag) {
            fclose($this->ofp);
        }
    }

    /**
     * 設定輸出目標
     * 
     * @param object|false $fp 輸出目標的 file point resource
     *                     如果沒給，會自動用 php://output 為輸出目標
     * @return void
     */
    public function setOutputTarget($fp)
    {
        if($this->ofp!==false) {
            throw new \Exception('cannot set output target twice');
        }
        $this->ofp=$fp;
    }
    
    /**
     * 設定加密物件
     *
     * @param  EncryptionInterface $encryptionObject 加密物件
     * @return void
     */
    public function setEncryptionObject($encryptionObject) {
        $this->encryptionObject = $encryptionObject;
    }

    /**
     * 保留一個 obj id 並回傳
     * 
     * @return int 預先保留的 obj id
     */
    public function preserveId()
    {
        $id=++$this->idCount;
        $this->preserveIdTable[$id]=true;
        return $id;
    }

    /**
     * 直接寫入一行內容，後面會自動加上 "\n"
     * 
     * @param string $content 要寫入的內容
     * @return void
     */
    public function writeLine($content)
    {
        $this->initOutputTarget();
        fwrite($this->ofp, "$content\n");
        $this->pos+=strlen($content)+1;
    }

    /**
     * 寫入到 dict
     * 
     * @param string $constet 這個dict 內的內容
     * @param int|false $id 預先保留的 id，如果不給就是新增一個 id
     * @param array|false $cipherParts 要被加密的片段，格式：[start1, end1, start2, end2, ...]
     *                                 注意：「要被加密的片段」應該是 16 進位表示的 UTF16-BE 字串
     */
    public function writeDict($content, $id=false, $cipherParts = false)
    {
        $this->initId($id);
        if($this->encryptionObject !== null && is_array($cipherParts)) {
            $result = [];
            $n = count($cipherParts);
            $idx = 0;
            for($i = 1; $i < $n; $i += 2) {
                $startPos = $cipherParts[$i - 1];
                $endPos = $cipherParts[$i];
                if($idx < $startPos) {
                    $result[] = substr($content, $idx, $startPos - $idx);
                }
                $s = hex2bin(substr($content, $startPos, $endPos - $startPos));
                $s = $this->encryptionObject->encrypt($s, $id);
                $result[] = bin2hex($s);
                $idx = $endPos;
            }
            $result[] = substr($content, $idx);
            $content = implode('', $result);
        }
        $dictContent="$id 0 obj\n<<\n$content\n>>\nendobj\n";
        fwrite($this->ofp, $dictContent);
        $this->posTable[$id]=$this->pos;
        $this->pos+=strlen($dictContent);
        return $id;
    }

    /**
     * 寫入一個 stream
     * 
     * @param string $content stream 的內容
     * @param int $compressFlag 壓縮相關設定，參考 StreamWriter 的 const
     * @param array $entries 添加 dict 區塊資料
     * @param int|false $id 預先保留的 id，如果不給就是新增一個 id
     * @return int $id obj id
     */
    public function writeStream($content, $compressFlag=0, $entries=[], $id=false)
    {
        $this->initId($id);
        if($compressFlag&StreamWriter::GZIP) {
            $streamContent=gzcompress($content, Config::GZIP_LEVEL);
        } else {
            $streamContent=$content;
        }
        if($this->encryptionObject !== null) {
            $streamContent = $this->encryptionObject->encrypt($streamContent, $id);
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

    /**
     * 寫入任何一種 object，例如 string, array, ...
     * 
     * @param string $content object 內容
     * @param int|false $id 預先保留的 id，如果不給就是新增一個 id
     * @return int $id obj id
     */
    public function writeObject($content, $id=false)
    {
        $this->initId($id);
        $content="$id 0 obj\n$content\nendobj\n";
        fwrite($this->ofp, $content);
        $this->posTable[$id]=$this->pos;
        $this->pos+=strlen($content);
        return $id;
    }

    /**
     * 寫入最後的內容，像是 xref、trailer 等
     * 
     * @param int $rootId catalog dict 的 id
     * @param int|null $encId encryption dict 的 id
     * @return void
     */
    public function writeFinish($rootId, $encId)
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
        $this->writeLine(sprintf('/ID [<%s> <%s>]', $this->docId, $this->docId));
        if($encId !== null) {
            $this->writeLine(sprintf('/Encrypt %d 0 R', $encId));
        }
        $this->writeLine('>>');
        $this->writeLine('startxref');
        $this->writeLine($xrefPos);
        $this->writeLine('%%EOF');
    }
 
    /**
     * 取得 pdf 的 Id
     *
     * @return int pdf 的 Id
     */
    public function getDocId() {
        return $this->docId;
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
        $this->initOutputTarget();
    }

    private function initOutputTarget()
    {
        if($this->ofp===false) {
            $this->ofp=fopen('php://output', 'wb');
            $this->closeFlag=true;
        }
    }
}