<?php

namespace Appwrite\Utopia\Request;

use Utopia\Database\Database;
use Utopia\Route;

abstract class Filter
{
    private ?Route $route;
    private ?Database $dbForProject;

    public function __construct(Database $dbForProject = null, Route $route = null)
    {
        $this->route = $route;
        $this->dbForProject = $dbForProject;
    }

    /**
     * Parse params to another format.
     *
     * @param array $content
     * @param string $model
     *
     * @return array
     */
    abstract public function parse(array $content, string $model): array;

    /**
     * Get the database for the current project.
     *
     * @return null|Database
     */
    public function getDbForProject(): ?Database
    {
        return $this->dbForProject;
    }

    /**
     * Returns the value of the given route param key, or a default if not found or on error.
     *
     * @param string $key
     * @param mixed $default
     *
     * @return mixed
     */
    public function getParamValue(string $key, mixed $default = ''): mixed
    {
        try {
            $value = $this->route?->getParamValue($key) ?? $default;
        } catch (\Exception $e) {
            $value = $default;
        }

        return $value;
    }
}
