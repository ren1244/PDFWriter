<?php
namespace ren1244\PDFWriter\Encryption;

/**
 * 參考文件：PDF 32000-1:2008
 * 實作 7.6 Encryption 中所提到的 Algorithm 1 ~ Algorithm 5
 */
trait Algorithm128
{
    private $padding = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    private function rc4($key, $str)
    {
        $s = array();
        for ($i = 0; $i < 256; $i++) {
            $s[$i] = $i;
        }
        $j = 0;
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % strlen($key)])) % 256;
            $x = $s[$i];
            $s[$i] = $s[$j];
            $s[$j] = $x;
        }
        $i = 0;
        $j = 0;
        $res = '';
        for ($y = 0; $y < strlen($str); $y++) {
            $i = ($i + 1) % 256;
            $j = ($j + $s[$i]) % 256;
            $x = $s[$i];
            $s[$i] = $s[$j];
            $s[$j] = $x;
            $res .= $str[$y] ^ chr($s[($s[$i] + $s[$j]) % 256]);
        }
        return $res;
    }

    private function padStr($str)
    {
        return substr($str . $this->padding, 0, 32);
    }
    
    /**
     * Algorithm 1: 使用 RC-4 或 AES 加密資料
     *
     * @param  string $data 要被加密的資料
     * @param  int $objectId 此資料所屬 Object 的 id
     * @param  string $encryptionKey 密鑰
     * @param  int $keyLengthInByte 密鑰長度
     * @param  bool $aesFlag 是否為 AES，TRUE 採用 AES，FALSE 採用 RC-4
     * @return string 加密後的資料
     */
    private function alg1($data, $objectId, $encryptionKey, $keyLengthInByte, $aesFlag)
    {
        $key = $encryptionKey . pack('VXxx', $objectId);
        if ($aesFlag) {
            $key .= 'sAlT';
        }
        $len = min(16, $keyLengthInByte + 5);
        $key = substr(md5($key, true), 0, $len);
        if (!$aesFlag) {
            return $this->rc4($key, $data);
        }
        //AES
        $ivlen = openssl_cipher_iv_length($cipher = "AES-128-CBC");
        $iv = openssl_random_pseudo_bytes($ivlen);
        $ciphertext_raw = openssl_encrypt($data, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        return $iv . $ciphertext_raw;
    }
    
    /**
     * Algorithm 2: 從 userPassword 產生密鑰
     *
     * @param  string $userPass 使用者密碼
     * @param  string $oValue 先前計算出的 O 值（Algorithm 3）
     * @param  int $permission 權限 flag 值
     * @param  int $docId 此 PDF 的 ID
     * @param  int $revision 修訂號（R 值）
     * @param  bool $isMetadataEnc Metadata 是否加密（目前都為 true）
     * @param  int $keyLengthInByte 密鑰長度
     * @return string 密鑰
     */
    private function alg2($userPass, $oValue, $permission, $docId, $revision, $isMetadataEnc, $keyLengthInByte)
    {
        $v = $this->padStr($userPass);
        $v .= $oValue;
        $v .= pack('V', $permission);
        $v .= $docId;
        if ($revision >= 4 && !$isMetadataEnc) {
            // not test
            $v .= "\xff\xff\xff\xff";
        }
        $v = md5($v, true);
        $n = $keyLengthInByte;
        if ($revision >= 3) {
            for ($i = 0; $i < 50; ++$i) {
                $v = md5(substr($v, 0, $n), true);
            }
        }
        return substr($v, 0, $n);
    }
    
    /**
     * Algorithm 3: 計算 O 值
     *
     * @param  string $opwd 擁有者密碼
     * @param  string $upwd 使用者密碼
     * @param  int $revision 修訂號（R 值）
     * @param  int $keyLengthInByte 密鑰長度
     * @return string O 值
     */
    private function alg3($opwd, $upwd, $revision, $keyLengthInByte)
    {
        $s = $this->padStr($opwd);
        $s = md5($s, true);
        if ($revision >= 3) {
            for ($i = 0; $i < 50; ++$i) {
                $s = md5($s, true);
            }
        }
        $s = substr($s, 0, $keyLengthInByte);
        $s2 = $this->padStr($upwd);
        $r = $this->rc4($s, $s2);
        if ($revision >= 3) {
            for ($i = 0; $i < 19; ++$i) {
                $k = '';
                for ($j = 0; $j < $keyLengthInByte; ++$j) {
                    $k .= chr(ord($s[$j]) ^ ($i + 1));
                }
                $r = $this->rc4($k, $r);
            }
        }
        return $r;
    }
    
    /**
     * Algorithm 4: 計算 U 值（R = 2 使用，此時密鑰只能 40 bit）
     *
     * @param  string $encryptionKey 密鑰
     * @return string U 值
     */
    private function alg4($encryptionKey)
    {
        return $this->rc4($encryptionKey, $this->padding);
    }
    
    /**
     * Algorithm 5: 計算 U 值（R = 3 使用，密鑰 40 ~ 128 bit）
     *
     * @param  string $encryptionKey 密鑰
     * @param  int $docId 此 PDF 的 ID
     * @param  int $keyLengthInByte 密鑰長度
     * @return string U 值
     */
    private function alg5($encryptionKey, $docId, $keyLengthInByte)
    {
        $v = md5($this->padding . $docId, true);
        $v = $this->rc4($encryptionKey, $v);
        for ($i = 0; $i < 19; ++$i) {
            $k = '';
            for ($j = 0; $j < $keyLengthInByte; ++$j) {
                $k .= chr(ord($encryptionKey[$j]) ^ ($i + 1));
            }
            $v = $this->rc4($k, $v);
        }
        return $v . str_repeat(chr(0), 16);
    }
}