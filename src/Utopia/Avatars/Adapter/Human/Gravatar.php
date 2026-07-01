<?php

namespace Utopia\Avatars\Adapter\Human;

use Utopia\Avatars\Adapter;
use Utopia\Fetch\Client;

class Gravatar extends Adapter
{
    public function __construct(Client $client)
    {
        parent::__construct($client);
    }

    public function getName(): string
    {
        return 'gravatar';
    }

    public function getType(): string
    {
        return self::TYPE_HUMAN;
    }

    public function getParam(): string
    {
        return 'emailHash';
    }

    public static function hashEmail(string $email): string
    {
        return \md5(\strtolower(\trim($email)));
    }

    public function isValid(string $value): bool
    {
        return (bool) \preg_match('/^[a-f0-9]{32}$/i', $value);
    }

    public function getUrl(string $value, int $size): string
    {
        return 'https://www.gravatar.com/avatar/' . \strtolower($value) . '?d=404&s=' . $size;
    }
}
