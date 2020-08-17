<?php

namespace Appwrite\Database;

use Exception;

abstract class Adapter
{
    const DATA_TYPE_STRING = 'string';
    const DATA_TYPE_INTEGER = 'integer';
    const DATA_TYPE_FLOAT = 'float';
    const DATA_TYPE_BOOLEAN = 'boolean';
    const DATA_TYPE_OBJECT = 'object';
    const DATA_TYPE_DICTIONARY = 'dictionary';
    const DATA_TYPE_ARRAY = 'array';
    const DATA_TYPE_NULL = 'null';

    /**
     * @var string
     */
    protected $namespace = '';

    /**
     * @var array
     */
    protected $debug = [];

    /**
     * @param $key
     * @param $value
     *
     * @return $this
     */
    public function setDebug($key, $value)
    {
        $this->debug[$key] = $value;

        return $this;
    }

    /**
     * @return array
     */
    public function getDebug()
    {
        return $this->debug;
    }

    /**
     * return $this
     */
    public function resetDebug()
    {
        $this->debug = [];
    }

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
     * @param string $collection
     * @param string $id
     *
     * @return array
     */
    abstract public function getDocument($collection, $id);

    /**
     * Create Document
     *
     * @param array $data
     *
     * @return array
     */
    abstract public function createDocument(string $collection, array $data, array $unique = []);

    /**
     * Update Document.
     *
     * @param string $collection
     * @param array $data
     *
     * @return array
     */
    abstract public function updateDocument(string $collection, string $id, array $data);

    /**
     * Delete Node.
     *
     * @param string $collection
     * @param string $id
     *
     * @return array
     */
    abstract public function deleteDocument(string $collection, string $id);

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
    abstract public function find(array $options);

    /**
     * @param array $options
     *
     * @return int
     */
    abstract public function count(array $options);

    /**
     * Get Unique Document ID.
     */
    public function getId()
    {
        return \uniqid();
    }
}
