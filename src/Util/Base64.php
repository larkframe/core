<?php

namespace LarkFrame\Util;

class Base64
{
    /**
     * Base64 标准编码
     */
    public static function encode(string $data): string
    {
        return base64_encode($data);
    }

    /**
     * Base64 标准解码
     */
    public static function decode(string $data): string|false
    {
        return base64_decode($data);
    }

    /**
     * Base64URL 编码（URL 安全，+ → -，/ → _，去掉 =）
     */
    public static function urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64URL 解码
     */
    public static function urlDecode(string $data): string|false
    {
        $padding = strlen($data) % 4;
        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * 带过期时间的可逆加密/解密
     *
     * @param string $string 要加密/解密的字符串
     * @param string $operation 'ENCODE' 或 'DECODE'
     * @param string $key 加密密钥
     * @param int $expiry 密文有效期(秒)，0 为永久有效
     * @return string|false 处理后的字符串或失败时返回 false
     */
    public static function authcode(string $string, string $operation = 'ENCODE', string $key = '', int $expiry = 0): string|false
    {
        if ($operation !== 'ENCODE' && $operation !== 'DECODE') {
            return false;
        }

        if ($key === '') {
            $key = 'b03aae926e9d664b';
        }

        $saltLength = 16;
        $key = md5($key);
        $keya = md5(substr($key, 0, 16));
        $keyb = md5(substr($key, 16, 16));

        if ($operation === 'ENCODE') {
            try {
                $salt = random_bytes($saltLength);
            } catch (\Exception) {
                $salt = Rand::str($saltLength);
            }

            $expiryTime = $expiry ? time() + $expiry : 0;
            $data = pack('N', $expiryTime) . substr(md5($string . $keyb), 0, 16) . $string;
            $encrypted = static::rc4Crypt($data, $keya . md5($keya . $salt));

            return static::urlEncode($salt . $encrypted);
        }

        // DECODE
        $data = static::urlDecode($string);
        if ($data === false || strlen($data) < $saltLength) {
            return false;
        }

        $salt = substr($data, 0, $saltLength);
        $encrypted = substr($data, $saltLength);
        $decrypted = static::rc4Crypt($encrypted, $keya . md5($keya . $salt));

        if (strlen($decrypted) < 20) {
            return false;
        }

        $expiryTime = unpack('N', substr($decrypted, 0, 4))[1];
        $md5Check = substr($decrypted, 4, 16);
        $result = substr($decrypted, 20);

        if (substr(md5($result . $keyb), 0, 16) !== $md5Check) {
            return false;
        }

        if ($expiryTime > 0 && $expiryTime < time()) {
            return false;
        }

        return $result;
    }

    /**
     * RC4 流加密算法
     */
    private static function rc4Crypt(string $data, string $key): string
    {
        $s = [];
        $keyLength = strlen($key);
        $dataLength = strlen($data);

        for ($i = 0; $i < 256; $i++) {
            $s[$i] = $i;
        }

        $j = 0;
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $keyLength])) % 256;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }

        $out = '';
        $i = $j = 0;
        for ($k = 0; $k < $dataLength; $k++) {
            $i = ($i + 1) % 256;
            $j = ($j + $s[$i]) % 256;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $out .= $data[$k] ^ chr($s[($s[$i] + $s[$j]) % 256]);
        }

        return $out;
    }
}
