<?php

namespace Appwrite\Platform\Modules\Databases\Services\Registry;

use Utopia\Platform\Service;

/**
 * Abstract base class for service registrars in the Databases module.
 */
abstract class Base
{
    /**
     * Constructs the registrar and triggers the registration of actions.
     */
    public function __construct(Service $service)
    {
        $this->register($service);
    }

    /**
     * Register all HTTP actions related to this module.
     */
    abstract protected function register(Service $service): void;
}
