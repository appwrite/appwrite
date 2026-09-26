<?php

declare(strict_types=1);

namespace Utopia\DNS\Resolver;

class Google extends Proxy
{
    /**
     * Create a new Google resolver
     * Uses Google's public DNS servers (8.8.8.8 or 8.8.4.4)
     *
     * @param bool $useBackup Use backup server (8.8.4.4) instead of primary (8.8.8.8)
     */
    public function __construct(bool $useBackup = false)
    {
        parent::__construct($useBackup ? '8.8.4.4' : '8.8.8.8');
    }
}
