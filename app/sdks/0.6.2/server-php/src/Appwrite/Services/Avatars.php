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
     * You can use this endpoint to show different browser icons to your users.
     * The code argument receives the browser code as it appears in your user
     * /account/sessions endpoint. Use width, height and quality arguments to
     * change the output settings.
     *
     * @param string  $code
     * @param int  $width
     * @param int  $height
     * @param int  $quality
     * @throws Exception
     * @return array
     */
    public function getBrowser(string $code, int $width = 100, int $height = 100, int $quality = 100):array
    {
        $path   = str_replace(['{code}'], [$code], '/avatars/browsers/{code}');
        $params = [];

        $params['width'] = $width;
        $params['height'] = $height;
        $params['quality'] = $quality;

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get Credit Card Icon
     *
     * Need to display your users with your billing method or their payment
     * methods? The credit card endpoint will return you the icon of the credit
     * card provider you need. Use width, height and quality arguments to change
     * the output settings.
     *
     * @param string  $code
     * @param int  $width
     * @param int  $height
     * @param int  $quality
     * @throws Exception
     * @return array
     */
    public function getCreditCard(string $code, int $width = 100, int $height = 100, int $quality = 100):array
    {
        $path   = str_replace(['{code}'], [$code], '/avatars/credit-cards/{code}');
        $params = [];

        $params['width'] = $width;
        $params['height'] = $height;
        $params['quality'] = $quality;

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get Favicon
     *
     * Use this endpoint to fetch the favorite icon (AKA favicon) of a  any remote
     * website URL.
     *
     * @param string  $url
     * @throws Exception
     * @return array
     */
    public function getFavicon(string $url):array
    {
        $path   = str_replace([], [], '/avatars/favicon');
        $params = [];

        $params['url'] = $url;

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get Country Flag
     *
     * You can use this endpoint to show different country flags icons to your
     * users. The code argument receives the 2 letter country code. Use width,
     * height and quality arguments to change the output settings.
     *
     * @param string  $code
     * @param int  $width
     * @param int  $height
     * @param int  $quality
     * @throws Exception
     * @return array
     */
    public function getFlag(string $code, int $width = 100, int $height = 100, int $quality = 100):array
    {
        $path   = str_replace(['{code}'], [$code], '/avatars/flags/{code}');
        $params = [];

        $params['width'] = $width;
        $params['height'] = $height;
        $params['quality'] = $quality;

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get Image from URL
     *
     * Use this endpoint to fetch a remote image URL and crop it to any image size
     * you want. This endpoint is very useful if you need to crop and display
     * remote images in your app or in case you want to make sure a 3rd party
     * image is properly served using a TLS protocol.
     *
     * @param string  $url
     * @param int  $width
     * @param int  $height
     * @throws Exception
     * @return array
     */
    public function getImage(string $url, int $width = 400, int $height = 400):array
    {
        $path   = str_replace([], [], '/avatars/image');
        $params = [];

        $params['url'] = $url;
        $params['width'] = $width;
        $params['height'] = $height;

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get User Initials
     *
     * Use this endpoint to show your user initials avatar icon on your website or
     * app. By default, this route will try to print your logged-in user name or
     * email initials. You can also overwrite the user name if you pass the 'name'
     * parameter. If no name is given and no user is logged, an empty avatar will
     * be returned.
     * 
     * You can use the color and background params to change the avatar colors. By
     * default, a random theme will be selected. The random theme will persist for
     * the user's initials when reloading the same theme will always return for
     * the same initials.
     *
     * @param string  $name
     * @param int  $width
     * @param int  $height
     * @param string  $color
     * @param string  $background
     * @throws Exception
     * @return array
     */
    public function getInitials(string $name = '', int $width = 500, int $height = 500, string $color = '', string $background = ''):array
    {
        $path   = str_replace([], [], '/avatars/initials');
        $params = [];

        $params['name'] = $name;
        $params['width'] = $width;
        $params['height'] = $height;
        $params['color'] = $color;
        $params['background'] = $background;

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get QR Code
     *
     * Converts a given plain text to a QR code image. You can use the query
     * parameters to change the size and style of the resulting image.
     *
     * @param string  $text
     * @param int  $size
     * @param int  $margin
     * @param boolean  $download
     * @throws Exception
     * @return array
     */
    public function getQR(string $text, int $size = 400, int $margin = 1, boolean $download = false):array
    {
        $path   = str_replace([], [], '/avatars/qr');
        $params = [];

        $params['text'] = $text;
        $params['size'] = $size;
        $params['margin'] = $margin;
        $params['download'] = $download;

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

}