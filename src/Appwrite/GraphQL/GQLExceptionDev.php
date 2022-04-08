<?php

namespace Appwrite\GraphQL;

use GraphQL\Error\ClientAware;

class GQLExceptionDev extends \Exception implements ClientAware
{
    private string $version;
    private array $trace;

    function __construct(
        string $message = '',
        int    $code = 0,
        string $version = '',
        string $file = '',
        int    $line = -1,
        array  $trace = []
    )
    {
        parent::__construct($message, $code);
        $this->message = $message;
        $this->code = $code;
        $this->version = $version;
        $this->file = $file;
        $this->line = $line;
        $this->trace = $trace;
    }

    public function isClientSafe(): bool
    {
        return true;
    }

    public function getCategory(): string
    {
        return 'Appwrite Server Exception';
    }

    /**
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * @param string $version
     */
    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

}