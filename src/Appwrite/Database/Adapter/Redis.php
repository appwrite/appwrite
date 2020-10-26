<?php

namespace Appwrite\Database\Adapter;

use Utopia\Registry\Registry;
use Appwrite\Database\Adapter;
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
     * Get Document.
     *
     * @param string $id
     *
     * @return array
     *
     * @throws Exception
     */
    public function getDocument($id)
    {
        $output = \json_decode($this->getRedis()->get($this->getNamespace().':document-'.$id), true);

        if (!$output) {
            $output = $this->adapter->getDocument($id);
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

            $node = (!empty($nodes[$i])) ? $this->parseRelations(\json_decode($nodes[$i], true)) : $this->getDocument($node);

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
     * @param array $data
     *
     * @return array
     *
     * @throws Exception
     */
    public function createDocument(array $data = [], array $unique = [])
    {
        $data = $this->adapter->createDocument($data, $unique);

        $this->getRedis()->expire($this->getNamespace().':document-'.$data['$id'], 0);
        $this->getRedis()->expire($this->getNamespace().':document-'.$data['$id'], 0);

        return $data;
    }

    /**
     * Update Document.
     *
     * @param array $data
     *
     * @return array
     *
     * @throws Exception
     */
    public function updateDocument(array $data = [])
    {
        $data = $this->adapter->updateDocument($data);

        $this->getRedis()->expire($this->getNamespace().':document-'.$data['$id'], 0);
        $this->getRedis()->expire($this->getNamespace().':document-'.$data['$id'], 0);

        return $data;
    }

    /**
     * Delete Document.
     *
     * @param string $id
     *
     * @return array
     *
     * @throws Exception
     */
    public function deleteDocument(string $id)
    {
        $data = $this->adapter->deleteDocument($id);

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
    public function getCollection(array $options)
    {
        $data = $this->adapter->getCollection($options);
        $keys = [];

        foreach ($data as $node) {
            $keys[] = $this->getNamespace().':document-'.$node;
        }

        $nodes = (!empty($keys)) ? $this->getRedis()->mget($keys) : [];

        foreach ($data as $i => &$node) {
            $temp = (!empty($nodes[$i])) ? $this->parseRelations(\json_decode($nodes[$i], true)) : $this->getDocument($node);

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
    public function getCount(array $options)
    {
        return $this->adapter->getCount($options);
    }

    /**
     * Last Modified.
     *
     * Return Unix timestamp of last time a node queried in current session has been changed
     *
     * @return int
     */
    public function lastModified()
    {
        return 0;
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
    protected function getRedis(): Client
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
