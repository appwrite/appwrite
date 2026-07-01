<?php

namespace Utopia\Avatars;

use Utopia\Fetch\Client;

abstract class Adapter
{
    public const TYPE_HUMAN = 'human';
    public const TYPE_COMPANY = 'company';

    public function __construct(protected Client $client)
    {
    }

    abstract public function getName(): string;

    abstract public function getType(): string;

    abstract public function getParam(): string;

    abstract public function isValid(string $value): bool;

    abstract public function getUrl(string $value, int $size): string;

    public function fetch(string $value, int $size): ?string
    {
        if (!$this->isValid($value)) {
            return null;
        }

        try {
            $response = $this->client
                ->setAllowRedirects(false)
                ->fetch($this->getUrl($value, $size));
        } catch (\Throwable) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $body = $response->getBody();

        return empty($body) ? null : $body;
    }
}
