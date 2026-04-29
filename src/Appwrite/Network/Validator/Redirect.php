<?php

namespace Appwrite\Network\Validator;

use Appwrite\Network\Platform;

class Redirect extends Origin
{
    /**
     * Get Description
     * @return string
     */
    public function getDescription(): string
    {
        $platform = Platform::getNameByScheme($this->scheme);
        $host = $this->host ? '(' . $this->host . ')' : '';

        if (empty($this->host) && empty($this->scheme)) {
            return 'Invalid URI.';
        }

        return 'Invalid URI. Register your new client ' . $host . ' as a new '
            . $platform . ' platform on your project console dashboard';
    }
}
