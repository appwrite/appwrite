<?php

namespace Appwrite\Database;

use Exception;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Database\Validator\Structure;
use Appwrite\Database\Exception\Authorization as AuthorizationException;
use Appwrite\Database\Exception\Structure as StructureException;

class Database
{
    // System Core
    const COLLECTION_COLLECTIONS = 'collections';
    const COLLECTION_RULES = 'rules';
    const COLLECTION_INDEXES = 'indexes';
    const COLLECTION_PROJECTS = 'projects';
    const COLLECTION_WEBHOOKS = 'webhooks';
    const COLLECTION_KEYS = 'keys';
    const COLLECTION_TASKS = 'tasks';
    const COLLECTION_PLATFORMS = 'platforms';
    const COLLECTION_USAGES = 'usages'; //TODO add structure
    const COLLECTION_DOMAINS = 'domains';
    const COLLECTION_CERTIFICATES = 'certificates';
    const COLLECTION_USERS = 'users';
    const COLLECTION_TOKENS = 'tokens';
    const COLLECTION_MEMBERSHIPS = 'memberships';
    const COLLECTION_TEAMS = 'teams';
    const COLLECTION_FILES = 'files';
    const COLLECTION_FUNCTIONS = 'functions';
    const COLLECTION_TAGS = 'tags';
    const COLLECTION_EXECUTIONS = 'executions';
    
    // Var Types
    const VAR_TEXT = 'text';
    const VAR_INTEGER = 'integer';
    const VAR_FLOAT = 'float';
    const VAR_NUMERIC = 'numeric';
    const VAR_BOOLEAN = 'boolean';
    const VAR_DOCUMENT = 'document';
    const VAR_EMAIL = 'email';
    const VAR_URL = 'url';
    const VAR_IPV4 = 'ipv4';
    const VAR_IPV6 = 'ipv6';
    const VAR_KEY = 'key';
    
    // Index Types
    const INDEX_KEY = 'text';
    const INDEX_FULLTEXT = 'fulltext';
    const INDEX_UNIQUE = 'unique';
    const INDEX_SPATIAL = 'spatial';

    /**
     * @var array
     */
    static protected $filters = [];

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

        $this->adapter->setDatabase($this);

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
     * @return Document[]
     */
    public function find(array $options)
    {
        $options = \array_merge([
            'offset' => 0,
            'limit' => 15,
            'search' => '',
            'relations' => true,
            'orderField' => '$id',
            'orderType' => 'ASC',
            'orderCast' => 'int',
            'filters' => [],
        ], $options);

        $results = $this->adapter->find($options);

        foreach ($results as &$node) {
            $node = $this->decode(new Document($node));
        }

        return $results;
    }

    /**
     * @param array $options
     *
     * @return Document
     */
    public function findFirst(array $options)
    {
        $results = $this->find($options);
        return \reset($results);
    }

    /**
     * @param array $options
     *
     * @return Document
     */
    public function findLast(array $options)
    {
        $results = $this->find($options);
        return \end($results);
    }

    /**
     * @param array $options
     *
     * @return int
     */
    public function count(array $options)
    {
        $options = \array_merge([
            'filters' => [],
        ], $options);

        $results = $this->adapter->count($options);

        return $results;
    }

    /**
     * Create Collection
     * 
     * @param string $id
     * 
     * @return bool
     */
    public function createCollection(string $id): bool
    {
        return $this->adapter->createCollection($this->getDocument(self::COLLECTION_COLLECTIONS, $id), $id);
    }

    /**
     * Delete Collection
     * 
     * @param string $id
     * 
     * @return bool
     */
    public function deleteCollection(string $id): bool
    {
        return $this->adapter->deleteCollection($this->getDocument(self::COLLECTION_COLLECTIONS, $id));
    }

    /**
     * Create Attribute
     * 
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param bool $array
     * 
     * @return bool
     */
    public function createAttribute(string $collection, string $id, string $type, bool $array = false): bool
    {
        $collection = $this->getDocument(self::COLLECTION_COLLECTIONS, $collection);
        return $this->adapter->createAttribute($collection, $id, $type, $array);
    }

    /**
     * Delete Attribute
     * 
     * @param string $collection
     * @param string $id
     * @param bool $array
     * 
     * @return bool
     */
    public function deleteAttribute(string $collection, string $id, bool $array): bool
    {
        return $this->adapter->deleteAttribute($this->getDocument(self::COLLECTION_COLLECTIONS, $collection), $id, $array);
    }

    /**
     * Create Index
     *
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param array $attributes
     *
     * @return bool
     */
    public function createIndex(string $collection, string $id, string $type, array $attributes): bool
    {
        $collection = $this->getDocument(self::COLLECTION_COLLECTIONS, $collection);
        return $this->adapter->createIndex($collection, $id, $type, $attributes);
    }

    /**
     * Delete Index
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     */
    public function deleteIndex(string $collection, string $id): bool
    {
        $collection = $this->getDocument(self::COLLECTION_COLLECTIONS, $collection);
        return $this->adapter->deleteIndex($collection, $id);
    }

    /**
     * @param string $collection
     * @param string $id
     * @param bool $mock is mocked data allowed?
     * @param bool $decode
     *
     * @return Document
     */
    public function getDocument($collection, $id, bool $mock = true, bool $decode = true)
    {
        if ($id === '') {
            return new Document([]);
        }

        if($mock === true
            && isset($this->mocks[$id])) {
            $document = $this->mocks[$id];
        }
        else {
            $document = new Document($this->adapter->getDocument($this->getDocument(self::COLLECTION_COLLECTIONS, $collection), $id));
        }

        $validator = new Authorization($document, 'read');

        if (!$validator->isValid($document->getPermissions())) { // Check if user has read access to this document
            return new Document([]);
        }

        $document = ($decode) ? $this->decode($document) : $document;

        return $document;
    }

    /**
     * @param string $collection
     * @param array $data
     * @param array $unique
     *
     * @return Document|bool
     *
     * @throws AuthorizationException
     * @throws StructureException
     */
    public function createDocument(string $collection, array $data, array $unique = [])
    {
        if(isset($data['$id'])) {
            throw new Exception('Use update method instead of create');
        }

        $document = new Document($data);

        $validator = new Authorization($document, 'write');

        if (!$validator->isValid($document->getPermissions())) { // Check if user has write access to this document
            throw new AuthorizationException($validator->getDescription());
        }

        $validator = new Structure($this);
        $document = $this->encode($document);

        if (!$validator->isValid($document)) {
            throw new StructureException($validator->getDescription()); // var_dump($validator->getDescription()); return false;
        }
        
        $document = new Document($this->adapter->createDocument($this->getDocument(self::COLLECTION_COLLECTIONS, $collection), $document->getArrayCopy(), $unique));
        
        $document = $this->decode($document);

        return $document;
    }

    /**
     * @param array $collection
     * @param array $id
     * @param array $data
     *
     * @return Document|false
     *
     * @throws Exception
     */
    public function updateDocument(string $collection, string $id, array $data)
    {
        if (!isset($data['$id'])) {
            throw new Exception('Must define $id attribute');
        }

        $document = $this->getDocument($collection, $id); // TODO make sure user don\'t need read permission for write operations

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

        $new = $this->encode($new);

        $validator = new Structure($this);

        if (!$validator->isValid($new)) { // Make sure updated structure still apply collection rules (if any)
            throw new StructureException($validator->getDescription()); // var_dump($validator->getDescription()); return false;
        }

        $new = new Document($this->adapter->updateDocument($this->getDocument(self::COLLECTION_COLLECTIONS, $collection), $id, $new->getArrayCopy()));
        
        $new = $this->decode($new);

        return $new;
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

        $document = $this->getDocument($data['$collection'], $data['$id']); // TODO make sure user don\'t need read permission for write operations

        $validator = new Authorization($document, 'write');

        if (!$validator->isValid($document->getPermissions())) { // Check if user has write access to this document
            throw new AuthorizationException($validator->getDescription()); // var_dump($validator->getDescription()); return false;
        }

        $new = new Document($data);

        if (!$validator->isValid($new->getPermissions())) { // Check if user has write access to this document
            throw new AuthorizationException($validator->getDescription()); // var_dump($validator->getDescription()); return false;
        }

        $new = $this->encode($new);

        $validator = new Structure($this);

        if (!$validator->isValid($new)) { // Make sure updated structure still apply collection rules (if any)
            throw new StructureException($validator->getDescription()); // var_dump($validator->getDescription()); return false;
        }

        $new = new Document($this->adapter->updateDocument($this->getDocument(self::COLLECTION_COLLECTIONS, $new->getCollection()), $new->getId(), $new->getArrayCopy()));

        $new = $this->decode($new);

        return $new;
    }

    /**
     * @param string $collection
     * @param string $id
     *
     * @return Document|false
     *
     * @throws AuthorizationException
     */
    public function deleteDocument(string $collection, string $id)
    {
        $document = $this->getDocument($collection, $id);

        $validator = new Authorization($document, 'write');

        if (!$validator->isValid($document->getPermissions())) { // Check if user has write access to this document
            throw new AuthorizationException($validator->getDescription());
        }

        return new Document($this->adapter->deleteDocument($this->getDocument(self::COLLECTION_COLLECTIONS, $collection), $id));
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
     * @param string $mocks
     *
     * @return array
     */
    public function setMocks(array $mocks)
    {
        foreach ($mocks as $key => $mock) {
            $this->mocks[$key] = new Document($mock);
        }

        $this->adapter->setMocks($this->mocks);

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
     * Add Attribute Filter
     * 
     * @param string $name
     * @param callable $encode
     * @param callable $decode
     * 
     * return $this
     */
    static public function addFilter(string $name, callable $encode, callable $decode)
    {
        self::$filters[$name] = [
            'encode' => $encode,
            'decode' => $decode,
        ];
    }

    public function encode(Document $document):Document
    {
        if($document->getCollection() === null) {
            return $document;
        }

        $collection = $this->getDocument(self::COLLECTION_COLLECTIONS, $document->getCollection(), true , false);
        $rules = $collection->getAttribute('rules', []);

        foreach ($rules as $key => $rule) {
            $key = $rule->getAttribute('key', null);
            $filters = $rule->getAttribute('filter', null);
            $value = $document->getAttribute($key, null);

            if(($value !== null) && is_array($filters)) {
                foreach ($filters as $filter) {
                    $value = $this->encodeAttribute($filter, $value);
                    $document->setAttribute($key, $value);
                }
            }
        }

        return $document;
    }

    public function decode(Document $document):Document
    {
        if($document->getCollection() === null) {
            return $document;
        }

        $collection = $this->getDocument(self::COLLECTION_COLLECTIONS, $document->getCollection(), true , false);
        $rules = $collection->getAttribute('rules', []);

        foreach ($rules as $key => $rule) {
            $key = $rule->getAttribute('key', null);
            $filters = $rule->getAttribute('filter', null);
            $value = $document->getAttribute($key, null);

            if(($value !== null) && is_array($filters)) {
                foreach (array_reverse($filters) as $filter) {
                    $value = $this->decodeAttribute($filter, $value);
                    $document->setAttribute($key, $value);
                }
            }
        }

        return $document;
    }

    /**
     * Encode Attribute
     * 
     * @param string $name
     * @param mixed $value
     */
    static protected function encodeAttribute(string $name, $value)
    {
        if(!isset(self::$filters[$name])) {
            throw new Exception('Filter not found');
        }

        try {
            $value = self::$filters[$name]['encode']($value);
        } catch (\Throwable $th) {
            $value = null;
        }

        return $value;
    }

    /**
     * Decode Attribute
     * 
     * @param string $name
     * @param mixed $value
     */
    static protected function decodeAttribute(string $name, $value)
    {
        if(!isset(self::$filters[$name])) {
            throw new Exception('Filter not found');
        }

        try {
            $value = self::$filters[$name]['decode']($value);
        } catch (\Throwable $th) {
            $value = null;
        }

        return $value;
    }
}
