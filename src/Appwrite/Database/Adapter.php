<?php

namespace Appwrite\Database;

use Exception;

abstract class Adapter
{
    /**
     * @var string
     */
    protected $namespace = '';

    /**
     * Set Namespace.
     *
     * Set namespace to divide different scope of data sets
     *
     * @param $namespace
     *
     * @throws Exception
     *
     * @return bool
     */
    public function setNamespace($namespace)
    {
        if (empty($namespace)) {
            throw new Exception('Missing namespace');
        }

        $this->namespace = $namespace;

        return true;
    }

    /**
     * Get Namespace.
     *
     * Get namespace of current set scope
     *
     * @throws Exception
     *
     * @return string
     */
    public function getNamespace()
    {
        if (empty($this->namespace)) {
            throw new Exception('Missing namespace');
        }

        return $this->namespace;
    }

    /**
     * Get Document.
     *
     * @param string $id
     *
     * @return array
     */
    abstract public function getDocument($id);

    /**
     * Create Document
     **.
     *
     * @param array $data
     *
     * @return array
     */
    abstract public function createDocument(array $data = [], array $unique = []);

    /**
     * Update Document.
     *
     * @param array $data
     *
     * @return array
     */
    abstract public function updateDocument(array $data = []);

    /**
     * Delete Node.
     *
     * @param string $id
     *
     * @return array
     */
    abstract public function deleteDocument(string $id);

    /**
     * Delete Unique Key.
     *
     * @param int $key
     *
     * @return array
     */
    abstract public function deleteUniqueKey($key);

    /**
     * Create Namespace.
     *
     * @param string $namespace
     *
     * @return bool
     */
    abstract public function createNamespace($namespace);

    /**
     * Delete Namespace.
     *
     * @param string $namespace
     *
     * @return bool
     */
    abstract public function deleteNamespace($namespace);

    /**
     * Filter.
     *
     * Filter data sets using chosen queries
     *
     * @param array $options
     *
     * @return array
     */
    abstract public function getCollection(array $options);

    /**
     * @param array $options
     *
     * @return int
     */
    abstract public function getCount(array $options);

    /**
     * Last Modified.
     *
     * Return Unix timestamp of last time a node queried in corrent session has been changed
     *
     * @return int
     */
    abstract public function lastModified();

    /**
     * Get Debug Data.
     *
     * @return array
     */
    abstract public function getDebug();
}
