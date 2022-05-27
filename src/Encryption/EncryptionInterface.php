<?php
namespace ren1244\PDFWriter\Encryption;

interface EncryptionInterface
{
    /**
     * 建構
     * 
     * @param  string $userPassword 使用者密碼
     * @param  string $ownerPassword 擁有者密碼
     * @param  int $permissionFlag 權限 flag，參考 \ren1244\PDFWriter::PERM_*
     * @param  int $docId 此 pdf trailer 的 ID 項目，其值陣列的第一個元素值（有些加密方法會參考這數值）
     * @return void
     */
    public function __construct($userPassword, $ownerPassword, $permissionFlag, $docId);    

    /**
     * 取得 Encryption Dictionary 的內容
     *
     * @return string Encryption Dictionary 的內容
     */
    public function getEncryptionDict();
    
    /**
     * 加密字串或串流
     *
     * @param  string $data 加密前的內容（stream 內容）
     * @param  int $objectId 此 stream 所屬的 object Id
     * @return string 加密後的結果
     */
    public function encrypt($data, $objectId);
}
