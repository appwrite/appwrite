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
     * You can use this endpoint to show different browser icons to your users,
     * The code argument receives the browser code as appear in your user
     * /account/sessions endpoint. Use width, height and quality arguments to
     * change the output settings.
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
     * Need to display your users with your billing method or there payment
     * methods? The credit card endpoint will return you the icon of the credit
     * card provider you need. Use width, height and quality arguments to change
     * the output settings.
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
     * Use this endpoint to fetch the favorite icon (AKA favicon) of a  any remote
     * website URL.
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
     * You can use this endpoint to show different country flags icons to your
     * users, The code argument receives the a 2 letter country code. Use width,
     * height and quality arguments to change the output settings.
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
     * Text to QR Generator
     *
     * Converts a given plain text to a QR code image. You can use the query
     * parameters to change the size and style of the resulting image.
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