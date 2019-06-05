<?php

namespace Auth\OAuth;

use Auth\OAuth;

class Apple extends OAuth
{
    //READ THE DOCS HERE: https://developer.apple.com/documentation/signinwithapplerestapi

    /**
     * @return string
     */
    public function getName(): string
    {
        // TODO: Implement getName() method.
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        // TODO: Implement getLoginURL() method.
    }

    /**
     * @param string $code
     * @return string
     */
    public function getAccessToken(string $code): string
    {
        // TODO: Implement getAccessToken() method.
    }

    /**
     * @param $accessToken
     * @return string
     */
    public function getUserID(string $accessToken): string
    {
        // TODO: Implement getUserID() method.
    }

    /**
     * @param $accessToken
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        // TODO: Implement getUserEmail() method.
    }

    /**
     * @param $accessToken
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        // TODO: Implement getUserName() method.
    }
}