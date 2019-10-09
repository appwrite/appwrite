<?php

namespace Appwrite\Services;

use Exception;
use Appwrite\Client;
use Appwrite\Service;

class Avatars extends Service
{
    /**
     * Get Browser Icon
     *
     * /docs/references/avatars/get-browser.md
     *
     * @param string $code
     * @param integer $width
     * @param integer $height
     * @param integer $quality
     * @throws Exception
     * @return array
     */
    public function getBrowser($code, $width = 100, $height = 100, $quality = 100)
    {
        $path   = str_replace(['{code}'], [$code], '/avatars/browsers/{code}');
        $params = [];

        $params['width'] = $width;
        $params['height'] = $height;
        $params['quality'] = $quality;

        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Get Credit Card Icon
     *
     * /docs/references/avatars/get-credit-cards.md
     *
     * @param string $code
     * @param integer $width
     * @param integer $height
     * @param integer $quality
     * @throws Exception
     * @return array
     */
    public function getCreditCard($code, $width = 100, $height = 100, $quality = 100)
    {
        $path   = str_replace(['{code}'], [$code], '/avatars/credit-cards/{code}');
        $params = [];

        $params['width'] = $width;
        $params['height'] = $height;
        $params['quality'] = $quality;

        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Get Favicon
     *
     * /docs/references/avatars/get-favicon.md
     *
     * @param string $url
     * @throws Exception
     * @return array
     */
    public function getFavicon($url)
    {
        $path   = str_replace([], [], '/avatars/favicon');
        $params = [];

        $params['url'] = $url;

        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Get Country Flag
     *
     * /docs/references/avatars/get-flag.md
     *
     * @param string $code
     * @param integer $width
     * @param integer $height
     * @param integer $quality
     * @throws Exception
     * @return array
     */
    public function getFlag($code, $width = 100, $height = 100, $quality = 100)
    {
        $path   = str_replace(['{code}'], [$code], '/avatars/flags/{code}');
        $params = [];

        $params['width'] = $width;
        $params['height'] = $height;
        $params['quality'] = $quality;

        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Get Image from URL
     *
     * /docs/references/avatars/get-image.md
     *
     * @param string $url
     * @param integer $width
     * @param integer $height
     * @throws Exception
     * @return array
     */
    public function getImage($url, $width = 400, $height = 400)
    {
        $path   = str_replace([], [], '/avatars/image');
        $params = [];

        $params['url'] = $url;
        $params['width'] = $width;
        $params['height'] = $height;

        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Text to QR Generator
     *
     * /docs/references/avatars/get-qr.md
     *
     * @param string $text
     * @param integer $size
     * @param integer $margin
     * @param integer $download
     * @throws Exception
     * @return array
     */
    public function getQR($text, $size = 400, $margin = 1, $download = 0)
    {
        $path   = str_replace([], [], '/avatars/qr');
        $params = [];

        $params['text'] = $text;
        $params['size'] = $size;
        $params['margin'] = $margin;
        $params['download'] = $download;

        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

}