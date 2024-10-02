<?php

namespace Appwrite\Utopia\Fetch;

use Utopia\Fetch\Client as UtopiaClient;
use Utopia\Fetch\Response;

class Client extends UtopiaClient
{
    protected string $baseUrl = '';

    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = $baseUrl;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function fetch(
        string $url,
        string $method = self::METHOD_GET,
        array $body = [],
        array $query = [],
    ): Response {
        $url = "{$this->baseUrl}{$url}";
        return parent::fetch($url, $method, $body, $query);
    }

}
