<?php
namespace ren1244\PDFWriter\Encryption;

class Rc4_128 implements EncryptionInterface
{
    private $oValue;
    private $uValue;
    private $permission;
    private $docId;
    private $encryptionKey;

    use Algorithm128;

    public function __construct($userPassword, $ownerPassword, $permissionFlag, $docId)
    {
        $this->permission = $permissionFlag;
        $this->docId = $docId;
        $this->oValue = $this->alg3($ownerPassword, $userPassword, 3, 16);
        $this->encryptionKey = $this->alg2(
            $userPassword, $this->oValue, $this->permission, $this->docId, 3, true, 16
        );
        $this->uValue = $this->alg5($this->encryptionKey, $this->docId, 16);
    }

    public function getEncryptionDict()
    {
        return implode("\n", [
            '/Filter /Standard',
            '/V 2',
            '/R 3',
            '/Length 128',
            '/O <' . bin2hex($this->oValue) . '>',
            '/U <' . bin2hex($this->uValue) . '>',
            '/P ' . $this->permission,
            '/EncryptMetadata true',
        ]);
    }

    public function encrypt($data, $objectId)
    {
        return $this->alg1($data, $objectId, $this->encryptionKey, 16, false);
    }
}
