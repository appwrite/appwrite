<?php

declare(strict_types=1);

namespace Utopia\VCS\Adapter\Git;

class Forgejo extends Gitea
{
    protected string $endpoint = 'http://forgejo:3000/api/v1';

    protected string $giteaUrl = 'http://forgejo:3000';

    /**
     * Get Adapter Name
     */
    #[\Override]
    public function getName(): string
    {
        return 'forgejo';
    }

    #[\Override]
    protected function getHookType(): string
    {
        return 'forgejo';
    }

    #[\Override]
    public function getEventHeaderName(): string
    {
        return 'x-forgejo-event';
    }

    #[\Override]
    public function getSignatureHeaderName(): string
    {
        return 'x-forgejo-signature';
    }
}
