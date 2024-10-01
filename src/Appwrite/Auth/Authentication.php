<?php

namespace Appwrite\Auth;

class Authentication
{
    /**
     * User Unique ID.
     *
     * @var string
     */
    private $unique = '';

    /**
     * User Secret Key.
     *
     * @var string
     */
    private $secret = '';


    /**
     * @var string
     */
    private $cookieName = 'a_session';

    public function setCookieName($string): string
    {
        return $this->cookieName = $string;
    }

    public function getCookieName(): string
    {
        return $this->cookieName;
    }

    public function getUnique(): string
    {
        return $this->unique;
    }

    public function setUnique(string $unique): void
    {
        $this->unique = $unique;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function setSecret(string $secret): void
    {
        $this->secret = $secret;
    }

}
