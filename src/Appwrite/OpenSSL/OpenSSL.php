<?php

namespace Appwrite\OpenSSL;

class OpenSSL
{
    public const CIPHER_AES_128_GCM = 'aes-128-gcm';

    /**
     * @param $data
     * @param $method
     * @param $key
     * @param int    $options
     * @param string $iv
     * @param null   $tag
     * @param string $aad
     * @param int    $tag_length
     *
     * @return string
     */
    public static function encrypt($data, $method, $key, $options = 0, $iv = '', &$tag = null, $aad = '', $tag_length = 16)
    {
        return \openssl_encrypt($data, $method, $key, $options, $iv, $tag, $aad, $tag_length);
    }

    /**
     * @param $data
     * @param $method
     * @param $password
     * @param int    $options
     * @param string $iv
     * @param string $tag
     * @param string $aad
     *
     * @return string
     */
    public static function decrypt($data, $method, $password, $options = 1, $iv = '', $tag = '', $aad = '')
    {
        return \openssl_decrypt($data, $method, $password, $options, $iv, $tag, $aad);
    }

    /**
     * @param string $method
     *
     * @return int
     */
    public static function cipherIVLength($method)
    {
        return \openssl_cipher_iv_length($method);
    }

    /**
     * @param $length
     * @param null $crypto_strong
     *
     * @return false|string
     */
    public static function randomPseudoBytes($length, &$crypto_strong = null)
    {
        return \openssl_random_pseudo_bytes($length, $crypto_strong);
    }

    /**
     * Secret String
     *
     * Generate random encryption secret
     *
     * @param int $length
     *
     * @return string
     *
     * @throws \Exception
     */
    public static function secretString(int $length = 128): string
    {
        return \bin2hex(self::randomPseudoBytes($length));
    }
}
