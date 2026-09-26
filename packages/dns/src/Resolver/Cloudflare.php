<?php

declare(strict_types=1);

namespace Utopia\DNS\Resolver;

class Cloudflare extends Proxy
{
    /**
     * Create a new Cloudflare resolver
     * Uses Cloudflare's public DNS servers (1.1.1.1 or 1.0.0.1)
     *
     * @param bool $useBackup Use backup server (1.0.0.1) instead of primary (1.1.1.1)
     */
    public function __construct(bool $useBackup = false)
    {
        parent::__construct($useBackup ? '1.0.0.1' : '1.1.1.1');
    }
}
