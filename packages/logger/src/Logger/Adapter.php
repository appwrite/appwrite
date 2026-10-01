<?php

namespace Utopia\Logger;

use Exception;

abstract class Adapter
{
    /**
     * Get unique name of an adapter
     *
     * @return string
     */
    abstract public static function getName(): string;

    /**
     * Push log to external provider
     *
     * @param  Log  $log
     * @return int
     *
     * @throws Exception
     */
    abstract public function push(Log $log): int;

    /**
     * Return a list of log types supported by Adapter
     *
     * @return string[]
     */
    abstract public function getSupportedTypes(): array;

    /**
     * Return a list of environment types supported by Adapter
     *
     * @return string[]
     */
    abstract public function getSupportedEnvironments(): array;

    /**
     * Return a list of breadcrumb types supported by Adapter
     *
     * @return string[]
     */
    abstract public function getSupportedBreadcrumbTypes(): array;

    /**
     * Validate a log for compatibility with specific adapter.
     *
     * @param  Log  $log
     * @return bool
     *
     * @throws Exception
     */
    public function validate(Log $log): bool
    {
        $supportedLogTypes = $this->getSupportedTypes();
        $supportedEnvironments = $this->getSupportedEnvironments();
        $supportedBreadcrumbTypes = $this->getSupportedBreadcrumbTypes();

        if (! \in_array($log->getType(), $supportedLogTypes)) {
            throw new Exception('Supported log types for this adapter are: '.\implode(', ', $supportedLogTypes));
        }
        if (! \in_array($log->getEnvironment(), $supportedEnvironments)) {
            throw new Exception('Supported environments for this adapter are: '.\implode(', ', $supportedEnvironments));
        }

        foreach ($log->getBreadcrumbs() as $breadcrumb) {
            if (! \in_array($breadcrumb->getType(), $supportedBreadcrumbTypes)) {
                throw new Exception('Supported breadcrumb types for this adapter are: '.\implode(', ', $supportedBreadcrumbTypes));
            }
        }

        return true;
    }
}
