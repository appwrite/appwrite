<?php

namespace Appwrite\Utopia\VCS\Adapter\Git;

use Utopia\VCS\Adapter\Git\GitHub as Base;

/**
 * The library accepts self-signed certificates on every provider API call by default;
 * GitHub's API always presents a publicly trusted certificate, so verify it.
 */
class GitHub extends Base
{
    protected bool $selfSigned = false;
}
