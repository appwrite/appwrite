<?php

namespace Utopia\Avatars\Adapter\Human;

use Utopia\Avatars\Adapter;
use Utopia\Fetch\Client;

class Github extends Adapter
{
    public function __construct(Client $client)
    {
        parent::__construct($client);
    }

    public function getName(): string
    {
        return 'github';
    }

    public function getType(): string
    {
        return self::TYPE_HUMAN;
    }

    public function getParam(): string
    {
        return 'github';
    }

    public function isValid(string $value): bool
    {
        return (bool) \preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9]|-(?=[a-zA-Z0-9])){0,38}$/', $value);
    }

    public function getUrl(string $value, int $size): string
    {
        return 'https://avatars.githubusercontent.com/' . $value . '?s=' . $size;
    }
}
