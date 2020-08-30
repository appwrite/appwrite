<?php

namespace Appwrite\Database\Adapter;

use Utopia\Registry\Registry;
use Appwrite\Database\Adapter;
use Appwrite\Database\Document;
use Exception;
use Redis as Client;

class Redis extends Adapter
{
    /**
     * @var Registry
     */
    protected $register;

    /**
     * @var Adapter
     */
    protected $adapter;

    /**
     * Redis constructor.
     *
     * @param Adapter  $adapter
     * @param Registry $register
     */
    public function __construct(Adapter $adapter, Registry $register)
    {
        $this->register = $register;
        $this->adapter = $adapter;
    }

    /**
     * Create Collection
     *
     * @param Document $collection
     * @param string $id
     *
     * @return bool
     */
    public function createCollection(Document $collection, string $id): bool
    {
        return $this->adapter->createCollection($collection, $id);
    }

    /**
     * Delete Collection
     *
     * @param Document $collection
     *
     * @return bool
     */
    public function deleteCollection(Document $collection): bool
    {
        return $this->adapter->deleteCollection($collection);
    }

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
    public function createAttribute(Document $collection, string $id, string $type, bool $array = false): bool
    {
        return $this->adapter->createAttribute($collection, $id, $type, $array);
    }

    /**
     * Delete Attribute
     * 
     * @param Document $collection
     * @param string $id
     * @param bool $array
     * 
     * @return bool
     */
    public function deleteAttribute(Document $collection, string $id, bool $array = false): bool
    {
        return $this->adapter->deleteAttribute($collection, $id, $array);
    }

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
    public function createIndex(Document $collection, string $id, string $type, array $attributes): bool
    {
        return $this->adapter->createIndex($collection, $id, $type, $attributes);
    }

    /**
     * Delete Index
     *
     * @param Document $collection
     * @param string $id
     *
     * @return bool
     */
    public function deleteIndex(Document $collection, string $id): bool
    {
        return $this->adapter->deleteIndex($collection, $id);
    }

    /**
     * Get Document.
     *
     * @param Document $collection
     * @param string $id
     *
     * @return array
     *
     * @throws Exception
     */
    public function getDocument(Document $collection, $id)
    {
        $output = \json_decode($this->getRedis()->get($this->getNamespace().':document-'.$id), true);

        if (!$output) {
            $output = $this->adapter->getDocument($collection, $id);
            $this->getRedis()->set($this->getNamespace().':document-'.$id, \json_encode($output, JSON_UNESCAPED_UNICODE));
        }

        $output = $this->parseRelations($output);

        return $output;
    }

    /**
     * @param $output
     *
     * @return mixed
     *
     * @throws Exception
     */
    protected function parseRelations($output)
    {
        $keys = [];

        if (empty($output) || !isset($output['temp-relations'])) {
            return $output;
        }

        foreach ($output['temp-relations'] as $relationship) {
            $keys[] = $this->getNamespace().':document-'.$relationship['end'];
        }

        $nodes = (!empty($keys)) ? $this->getRedis()->mget($keys) : [];

        foreach ($output['temp-relations'] as $i => $relationship) {
            $node = $relationship['end'];

            $node = (!empty($nodes[$i])) ? $this->parseRelations(\json_decode($nodes[$i], true)) : $this->getDocument('', $node);

            if (empty($node)) {
                continue;
            }

            if ($relationship['array']) {
                $output[$relationship['key']][] = $node;
            } else {
                $output[$relationship['key']] = $node;
            }
        }

        unset($output['temp-relations']);

        return $output;
    }

    /**
     * Create Document.
     *
     * @param Document $collection
     * @param array $data
     * @param array $unique
     *
     * @return array
     *
     * @throws Exception
     */
    public function createDocument(Document $collection, array $data, array $unique = [])
    {
        $data = $this->adapter->createDocument($collection, $data, $unique);

        $this->getRedis()->expire($this->getNamespace().':document-'.$data['$id'], 0);
        $this->getRedis()->expire($this->getNamespace().':document-'.$data['$id'], 0);

        return $data;
    }

    /**
     * Update Document.
     *
     * @param Document $collection
     * @param string $id
     * @param array $data
     *
     * @return array
     *
     * @throws Exception
     */
    public function updateDocument(Document $collection, string $id, array $data)
    {
        $data = $this->adapter->updateDocument($collection, $id, $data);

        $this->getRedis()->expire($this->getNamespace().':document-'.$data['$id'], 0);
        $this->getRedis()->expire($this->getNamespace().':document-'.$data['$id'], 0);

        return $data;
    }

    /**
     * Delete Document.
     *
     * @param Document $collection
     * @param string $id
     *
     * @return array
     *
     * @throws Exception
     */
    public function deleteDocument(Document $collection, string $id)
    {
        $data = $this->adapter->deleteDocument($collection, $id);

        $this->getRedis()->expire($this->getNamespace().':document-'.$id, 0);
        $this->getRedis()->expire($this->getNamespace().':document-'.$id, 0);

        return $data;
    }

    /**
     * Delete Unique Key.
     *
     * @param $key
     *
     * @return array
     *
     * @throws Exception
     */
    public function deleteUniqueKey($key)
    {
        $data = $this->adapter->deleteUniqueKey($key);

        return $data;
    }

    /**
     * Create Namespace.
     *
     * @param string $namespace
     *
     * @return bool
     */
    public function createNamespace($namespace)
    {
        return $this->adapter->createNamespace($namespace);
    }

    /**
     * Delete Namespace.
     *
     * @param string $namespace
     *
     * @return bool
     */
    public function deleteNamespace($namespace)
    {
        return $this->adapter->deleteNamespace($namespace);
    }

    /**
     * @param array $options
     *
     * @return array
     *
     * @throws Exception
     */
    public function find(array $options)
    {
        $data = $this->adapter->find($options);
        $keys = [];

        foreach ($data as $node) {
            $keys[] = $this->getNamespace().':document-'.$node;
        }

        $nodes = (!empty($keys)) ? $this->getRedis()->mget($keys) : [];

        foreach ($data as $i => &$node) {
            $temp = (!empty($nodes[$i])) ? $this->parseRelations(\json_decode($nodes[$i], true)) : $this->getDocument('', $node);

            if (!empty($temp)) {
                $node = $temp;
            }
        }

        return $data;
    }

    /**
     * @param array $options
     *
     * @return int
     *
     * @throws Exception
     */
    public function count(array $options)
    {
        return $this->adapter->count($options);
    }

    /**
     * @return array
     */
    public function getDebug()
    {
        return $this->adapter->getDebug();
    }

    /**
     * @throws Exception
     *
     * @return Client
     */
    protected function getRedis():Client
    {
        return $this->register->get('cache');
    }

    /**
     * Set Namespace.
     *
     * Set namespace to divide different scope of data sets
     *
     * @param $namespace
     *
     * @return bool
     *
     * @throws Exception
     */
    public function setNamespace($namespace)
    {
        $this->adapter->setNamespace($namespace);

        return parent::setNamespace($namespace);
    }
}
