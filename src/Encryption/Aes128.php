<?php
namespace ren1244\PDFWriter\Encryption;

class Aes128 implements EncryptionInterface
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
        $this->oValue = $this->alg3($ownerPassword, $userPassword, 4, 16);
        $this->encryptionKey = $this->alg2(
            $userPassword, $this->oValue, $this->permission, $this->docId, 4, true, 16
        );
        $this->uValue = $this->alg5($this->encryptionKey, $this->docId, 16);
    }

    public function getEncryptionDict()
    {
        return implode("\n", [
            '/Filter /Standard',
            '/V 4',
            '/Length 128',
            '/CF << /StdCF <<',
            '/Type /CryptFilter',
            '/CFM /AESV2',
            '/AuthEvent /DocOpen',
            '/Length 128',
            '>> >>',
            '/StrF /StdCF',
            '/StmF /StdCF',
            '/R 4',
            '/O <' . bin2hex($this->oValue) . '>',
            '/U <' . bin2hex($this->uValue) . '>',
            '/P ' . $this->permission,
            '/EncryptMetadata true',
        ]);
    }

    public function encrypt($data, $objectId)
    {
        return $this->alg1($data, $objectId, $this->encryptionKey, 16, true);
    }
}
