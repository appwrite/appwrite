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
