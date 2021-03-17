<?php

namespace Appwrite\OpenSSL;

class OpenSSL
{
    const CIPHER_AES_128_GCM = 'aes-128-gcm';

    /**
     * @param string $data
     * @param string $method
     * @param string $key
     * @param int    $options
     * @param string $iv
     * @param null   $tag
     * @param string $aad
     * @param int    $tag_length
     *
     * @return string
     */
    public static function encrypt(string $data, string $method, string $key, int $options = 0, string $iv = '', string &$tag = null, string $aad = '', int $tag_length = 16)
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
     * @param int $length
     * @param bool $crypto_strong
     *
     * @return false|string
     */
    public static function randomPseudoBytes(int $length, bool &$crypto_strong = null)
    {
        return \openssl_random_pseudo_bytes($length, $crypto_strong);
    }
}
