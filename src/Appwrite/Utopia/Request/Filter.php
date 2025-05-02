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
     * Get the current route context.
     *
     * @return null|Route
     */
    public function getRoute(): ?Route
    {
        return $this->route;
    }

    /**
     * Get the database for the current project.
     *
     * @return null|Database
     */
    public function getDbForProject(): ?Database
    {
        return $this->dbForProject;
    }
}
