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
     * @var array
     */
    protected $debug = [];

    /**
     * @var array
     */
    protected $mocks = [];

    /**
     * @var Database
     */
    protected $database = null;

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
     * Create Collection
     * 
     * @param Document $collection
     * @param string $id
     * 
     * @return bool
     */
    abstract public function createCollection(Document $collection, string $id): bool;

    /**
     * Delete Collection
     * 
     * @param Document $collection
     * 
     * @return bool
     */
    abstract public function deleteCollection(Document $collection): bool;

    /**
     * Create Attribute
     * 
     * @param Document $collection
     * @param string $id
     * @param string $type
     * @param bool $array
     * 
     * @return bool
     */
    abstract public function createAttribute(Document $collection, string $id, string $type, bool $array = false): bool;

    /**
     * Delete Attribute
     * 
     * @param Document $collection
     * @param string $id
     * @param bool $array
     * 
     * @return bool
     */
    abstract public function deleteAttribute(Document $collection, string $id, bool $array = false): bool;

    /**
     * Create Index
     *
     * @param Document $collection
     * @param string $id
     * @param string $type
     * @param array $attributes
     *
     * @return bool
     */
    abstract public function createIndex(Document $collection, string $id, string $type, array $attributes): bool;

    /**
     * Delete Index
     *
     * @param Document $collection
     * @param string $id
     *
     * @return bool
     */
    abstract public function deleteIndex(Document $collection, string $id): bool;

    /**
     * Get Document.
     *
     * @param Document $collection
     * @param string $id
     *
     * @return array
     */
    abstract public function getDocument(Document $collection, $id);

    /**
     * Create Document
     *
     * @param Document $collection
     * @param array $data
     * @param array $unique
     *
     * @return array
     */
    abstract public function createDocument(Document $collection, array $data, array $unique = []);

    /**
     * Update Document.
     *
     * @param Document $collection
     * @param array $data
     *
     * @return array
     */
    abstract public function updateDocument(Document $collection, string $id, array $data);

    /**
     * Delete Node.
     *
     * @param Document $collection
     * @param string $id
     *
     * @return array
     */
    abstract public function deleteDocument(Document $collection, string $id);

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

    /**
     * @param Database $database
     *
     * @return $this
     */
    public function setDatabase(Database $database)
    {
        $this->database = $database;
        
        return $this;
    }

    /**
     * @return Database
     */
    public function getDatabase()
    {
        return $this->database;
    }

    /**
     * @param string $mocks
     *
     * @return $this
     */
    public function setMocks(array $mocks)
    {
        $this->mocks = $mocks;
        
        return $this;
    }

    /**
     * @return array
     */
    public function getMocks()
    {
        return $this->mocks;
    }
}
