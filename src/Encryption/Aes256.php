<?php

namespace ren1244\PDFWriter\Encryption;

class Aes256 implements EncryptionInterface
{
    private $oValue;
    private $uValue;
    private $oeValue;
    private $ueValue;
    private $perm;
    private $permission;
    private $encryptionKey;

    public function __construct($userPassword, $ownerPassword, $permissionFlag, $docId)
    {
        $this->permission = $permissionFlag;
        $this->encryptionKey = openssl_random_pseudo_bytes(32);
        $this->initXAndXE($this->uValue, $this->ueValue, $userPassword);
        $this->initXAndXE($this->oValue, $this->oeValue, $ownerPassword, $this->uValue);
        $this->perm = $this->getPerm();
    }

    public function getEncryptionDict()
    {
        return implode("\n", [
            '/Filter /Standard',
            '/V 5',
            '/Length 256',
            '/CF <<',
            '/StdCF <<',
            '/Type /CryptFilter',
            '/CFM /AESV3',
            '/AuthEvent /DocOpen',
            '/Length 256',
            '>>',
            '>>',
            '/StmF /StdCF',
            '/StrF /StdCF',
            '/R 5',
            '/OE <' . bin2hex($this->oeValue) . '>',
            '/UE <' . bin2hex($this->ueValue) . '>',
            '/Perms <' . bin2hex($this->perm) . '>',
            '/O <' . bin2hex($this->oValue) . '>',
            '/U <' . bin2hex($this->uValue) . '>',
            '/P ' . $this->permission,
            '/EncryptMetadata true'
        ]);
    }

    public function encrypt($data, $objectId)
    {
        $iv = openssl_random_pseudo_bytes(16);
        $cipher = openssl_encrypt($data, 'AES-256-CBC', $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
        return $iv . $cipher;
    }

    private function initXAndXE(&$x, &$xe, $pass, $pad = '')
    {
        //設定 X
        $h = openssl_random_pseudo_bytes(16);
        $v = substr($h, 0, 8);
        $k = substr($h, 8, 16);
        $x = hash('sha256', $pass . $v . $pad, true) . $v . $k;
        //設定 XE
        $h = hash('sha256', $pass . $k, true);
        $iv = str_repeat("\x00", 16);
        $e = openssl_encrypt($this->encryptionKey, "AES-256-CBC", $h, OPENSSL_RAW_DATA, $iv);
        $xe = substr($e, 0, -16);
    }

    private function getPerm()
    {
        $data = pack('V', $this->permission) . "\xff\xff\xff\xff" . 'Tadbnick';
        $iv = str_repeat("\x00", 16);
        $cipher = openssl_encrypt($data, "AES-256-CBC", $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
        return substr($cipher, 0, -16);
    }
}
