<?php

namespace Database;

use Exception;
use Database\Validator\Authorization;
use Database\Validator\Structure;
use Database\Exception\Authorization as AuthorizationException;
use Database\Exception\Structure as StructureException;

class Database
{
    // System Core
    const SYSTEM_COLLECTION_COLLECTIONS = 0;
    const SYSTEM_COLLECTION_RULES = 'rules';

    // Project
    const SYSTEM_COLLECTION_PROJECTS = 'projects';
    const SYSTEM_COLLECTION_WEBHOOKS = 'webhooks';
    const SYSTEM_COLLECTION_KEYS = 'keys';
    const SYSTEM_COLLECTION_TASKS = 'tasks';
    const SYSTEM_COLLECTION_PLATFORMS = 'platforms';
    const SYSTEM_COLLECTION_USAGES = 'usages'; //TODO add structure

    // Auth, Account and Users (private to user)
    const SYSTEM_COLLECTION_USERS = 'users';
    const SYSTEM_COLLECTION_TOKENS = 'tokens';

    // Teams (shared among team members)
    const SYSTEM_COLLECTION_MEMBERSHIPS = 'memberships';
    const SYSTEM_COLLECTION_TEAMS = 'teams';

    // Storage
    const SYSTEM_COLLECTION_FILES = 'files';

    /**
     * @var array
     */
    protected $mocks = [];

    /**
     * @var Adapter
     */
    protected $adapter;

    /**
     * Set Adapter.
     *
     * @param Adapter $adapter
     *
     * @return $this
     */
    public function setAdapter(Adapter $adapter)
    {
        $this->adapter = $adapter;

        return $this;
    }

    /**
     * Set Namespace.
     *
     * Set namespace to divide different scope of data sets
     *
     * @param $namespace
     *
     * @return $this
     *
     * @throws Exception
     */
    public function setNamespace($namespace)
    {
        $this->adapter->setNamespace($namespace);

        return $this;
    }

    /**
     * Get Namespace.
     *
     * Get namespace of current set scope
     *
     * @return string
     *
     * @throws Exception
     */
    public function getNamespace()
    {
        return $this->adapter->getNamespace();
    }

    /**
     * Create Namespace.
     *
     * @param int $namespace
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
     * @param int $namespace
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
     * @return Document[]|Document
     */
    public function getCollection(array $options)
    {
        $options = array_merge([
            'offset' => 0,
            'limit' => 15,
            'search' => '',
            'relations' => true,
            'orderField' => '$id',
            'orderType' => 'ASC',
            'orderCast' => 'int',
            'first' => false,
            'last' => false,
            'filters' => [],
        ], $options);

        $results = $this->adapter->getCollection($options);

        foreach ($results as &$node) {
            $node = new Document($node);
        }

        if ($options['first']) {
            $results = reset($results);
        }

        if ($options['last']) {
            $results = end($results);
        }

        return $results;
    }

    /**
     * @param int  $id
     * @param bool $mock is mocked data allowed?
     *
     * @return Document
     */
    public function getDocument($id, $mock = true)
    {
        if (is_null($id)) {
            return new Document([]);
        }

        $document = new Document((isset($this->mocks[$id]) && $mock) ? $this->mocks[$id] : $this->adapter->getDocument($id));
        $validator = new Authorization($document, 'read');

        if (!$validator->isValid($document->getPermissions())) { // Check if user has read access to this document
            return new Document([]);
        }

        return $document;
    }

    /**
     * @param array $data
     *
     * @return Document|bool
     *
     * @throws AuthorizationException
     * @throws StructureException
     */
    public function createDocument(array $data)
    {
        $document = new Document($data);

        $validator = new Authorization($document, 'write');

        if (!$validator->isValid($document->getPermissions())) { // Check if user has write access to this document
            throw new AuthorizationException($validator->getDescription());
        }

        $validator = new Structure($this);

        if (!$validator->isValid($document)) {
            throw new StructureException($validator->getDescription()); // var_dump($validator->getDescription()); return false;
        }

        return new Document($this->adapter->createDocument($data));
    }

    /**
     * @param array $data
     *
     * @return Document|false
     *
     * @throws Exception
     */
    public function updateDocument(array $data)
    {
        if (!isset($data['$id'])) {
            throw new Exception('Must define $id attribute');
        }

        $document = $this->getDocument($data['$id']); // TODO make sure user don\'t need read permission for write operations

        // Make sure reserved keys stay constant
        $data['$id'] = $document->getId();
        $data['$collection'] = $document->getCollection();

        $validator = new Authorization($document, 'write');

        if (!$validator->isValid($document->getPermissions())) { // Check if user has write access to this document
            throw new AuthorizationException($validator->getDescription()); // var_dump($validator->getDescription()); return false;
        }

        $new = new Document($data);

        if (!$validator->isValid($new->getPermissions())) { // Check if user has write access to this document
            throw new AuthorizationException($validator->getDescription()); // var_dump($validator->getDescription()); return false;
        }

        $validator = new Structure($this);

        if (!$validator->isValid($new)) { // Make sure updated structure still apply collection rules (if any)
            throw new StructureException($validator->getDescription()); // var_dump($validator->getDescription()); return false;
        }

        return new Document($this->adapter->updateDocument($data));
    }

    /**
     * @param array $data
     *
     * @return Document|false
     *
     * @throws Exception
     */
    public function overwriteDocument(array $data)
    {
        if (!isset($data['$id'])) {
            throw new Exception('Must define $id attribute');
        }

        $document = $this->getDocument($data['$id']); // TODO make sure user don\'t need read permission for write operations

        $validator = new Authorization($document, 'write');

        if (!$validator->isValid($document->getPermissions())) { // Check if user has write access to this document
            throw new AuthorizationException($validator->getDescription()); // var_dump($validator->getDescription()); return false;
        }

        $new = new Document($data);

        if (!$validator->isValid($new->getPermissions())) { // Check if user has write access to this document
            throw new AuthorizationException($validator->getDescription()); // var_dump($validator->getDescription()); return false;
        }

        $validator = new Structure($this);

        if (!$validator->isValid($new)) { // Make sure updated structure still apply collection rules (if any)
            throw new StructureException($validator->getDescription()); // var_dump($validator->getDescription()); return false;
        }

        return new Document($this->adapter->updateDocument($data));
    }

    /**
     * @param int $id
     *
     * @return Document|false
     *
     * @throws AuthorizationException
     */
    public function deleteDocument($id)
    {
        $document = $this->getDocument($id);

        $validator = new Authorization($document, 'write');

        if (!$validator->isValid($document->getPermissions())) { // Check if user has write access to this document
            throw new AuthorizationException($validator->getDescription());
        }

        return new Document($this->adapter->deleteDocument($id));
    }

    /**
     * @return array
     */
    public function getDebug()
    {
        return $this->adapter->getDebug();
    }

    /**
     * @return int
     */
    public function getSum()
    {
        $debug = $this->getDebug();

        return (isset($debug['sum'])) ? $debug['sum'] : 0;
    }

    /**
     * @param array $options
     *
     * @return int
     */
    public function getCount(array $options)
    {
        $options = array_merge([
            'filters' => [],
        ], $options);

        $results = $this->adapter->getCount($options);

        return $results;
    }

    /**
     * @param string $key
     * @param string $value
     *
     * @return array
     */
    public function setMock($key, $value)
    {
        $this->mocks[$key] = $value;

        return $this;
    }

    /**
     * @param string $mocks
     *
     * @return array
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

    /**
     * Get Last Modified.
     *
     * Return unix timestamp of last time a node queried in current session has been changed
     *
     * @return int
     */
    public function lastModified()
    {
        return $this->adapter->lastModified();
    }
}
