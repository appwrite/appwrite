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
    const SYSTEM_COLLECTION_COLLECTIONS = 0;
    const SYSTEM_COLLECTION_RULES = 'rules';

    // Project
    const SYSTEM_COLLECTION_PROJECTS = 'projects';
    const SYSTEM_COLLECTION_WEBHOOKS = 'webhooks';
    const SYSTEM_COLLECTION_KEYS = 'keys';
    const SYSTEM_COLLECTION_TASKS = 'tasks';
    const SYSTEM_COLLECTION_PLATFORMS = 'platforms';
    const SYSTEM_COLLECTION_USAGES = 'usages'; // TODO add structure
    const SYSTEM_COLLECTION_DOMAINS = 'domains';
    const SYSTEM_COLLECTION_CERTIFICATES = 'certificates';
    const SYSTEM_COLLECTION_RESERVED = 'reserved';

    // Auth, Account and Users (private to user)
    const SYSTEM_COLLECTION_USERS = 'users';
    const SYSTEM_COLLECTION_SESSIONS = 'sessions';
    const SYSTEM_COLLECTION_TOKENS = 'tokens';

    // Teams (shared among team members)
    const SYSTEM_COLLECTION_MEMBERSHIPS = 'memberships';
    const SYSTEM_COLLECTION_TEAMS = 'teams';

    // Storage
    const SYSTEM_COLLECTION_FILES = 'files';

    // Functions
    const SYSTEM_COLLECTION_FUNCTIONS = 'functions';
    const SYSTEM_COLLECTION_TAGS = 'tags';
    const SYSTEM_COLLECTION_EXECUTIONS = 'executions';
    
    // Var Types
    const SYSTEM_VAR_TYPE_TEXT = 'text';
    const SYSTEM_VAR_TYPE_NUMERIC = 'numeric';
    const SYSTEM_VAR_TYPE_BOOLEAN = 'boolean';
    const SYSTEM_VAR_TYPE_DOCUMENT = 'document';
    const SYSTEM_VAR_TYPE_WILDCARD = 'wildcard';
    const SYSTEM_VAR_TYPE_EMAIL = 'email';
    const SYSTEM_VAR_TYPE_IP = 'ip';
    const SYSTEM_VAR_TYPE_URL = 'url';
    const SYSTEM_VAR_TYPE_KEY = 'key';

    /**
     * @var array
     */
    static protected $filters = [];

    /**
     * @var bool
     */
    static protected $statusFilters = true;

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
     * @param array $filterTypes
     *
     * @return Document[]
     */
    public function getCollection(array $options, array $filterTypes = [])
    {
        $options = \array_merge([
            'offset' => 0,
            'limit' => 15,
            'search' => '',
            'relations' => true,
            'orderField' => '',
            'orderType' => 'ASC',
            'orderCast' => 'int',
            'filters' => [],
        ], $options);

        $results = $this->adapter->getCollection($options, $filterTypes);

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
    public function getCollectionFirst(array $options)
    {
        $results = $this->getCollection($options);
        return \reset($results);
    }

    /**
     * @param array $options
     *
     * @return Document
     */
    public function getCollectionLast(array $options)
    {
        $results = $this->getCollection($options);
        return \end($results);
    }

    /**
     * @param string $id
     * @param bool $mock is mocked data allowed?
     * @param bool $decode enable decoding?
     *
     * @return Document
     */
    public function getDocument($id, bool $mock = true, bool $decode = true)
    {
        if (\is_null($id)) {
            return new Document();
        }

        $document = new Document((isset($this->mocks[$id]) && $mock) ? $this->mocks[$id] : $this->adapter->getDocument($id));
        $validator = new Authorization($document, 'read');

        if (!$validator->isValid($document->getPermissions())) { // Check if user has read access to this document
            return new Document();
        }

        $document = ($decode) ? $this->decode($document) : $document;

        return $document;
    }

    /**
     * @param array $data
     *
     * @return Document
     *
     * @throws AuthorizationException
     * @throws StructureException
     */
    public function createDocument(array $data, array $unique = [])
    {
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
        
        $document = new Document($this->adapter->createDocument($document->getArrayCopy(), $unique));
        
        $document = $this->decode($document);

        return $document;
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

        $new = $this->encode($new);

        $validator = new Structure($this);

        if (!$validator->isValid($new)) { // Make sure updated structure still apply collection rules (if any)
            throw new StructureException($validator->getDescription()); // var_dump($validator->getDescription()); return false;
        }

        $new = new Document($this->adapter->updateDocument($new->getArrayCopy()));
        
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

        $document = $this->getDocument($data['$id']); // TODO make sure user don\'t need read permission for write operations

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

        $new = new Document($this->adapter->updateDocument($new->getArrayCopy()));

        $new = $this->decode($new);

        return $new;
    }

    /**
     * @param string $id
     *
     * @return Document|false
     *
     * @throws AuthorizationException
     */
    public function deleteDocument(string $id)
    {
        $document = $this->getDocument($id);

        $validator = new Authorization($document, 'write');

        if (!$validator->isValid($document->getPermissions())) { // Check if user has write access to this document
            throw new AuthorizationException($validator->getDescription());
        }

        return new Document($this->adapter->deleteDocument($id));
    }

    /**
     * @param int $key
     *
     * @return Document|false
     *
     * @throws AuthorizationException
     */
    public function deleteUniqueKey($key)
    {
        return new Document($this->adapter->deleteUniqueKey($key));
    }

    /**
     * @param int $key
     *
     * @return Document|false
     *
     * @throws AuthorizationException
     */
    public function addUniqueKey($key)
    {
        return new Document($this->adapter->addUniqueKey($key));
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
        $options = \array_merge([
            'filters' => [],
        ], $options);

        $results = $this->adapter->getCount($options);

        return $results;
    }

    /**
     * @param string $key
     * @param string $value
     *
     * @return self
     */
    public function setMock($key, $value): self
    {
        $this->mocks[$key] = $value;

        return $this;
    }

    /**
     * @param array $mocks
     *
     * @return self
     */
    public function setMocks(array $mocks): self
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
     * Add Attribute Filter
     *
     * @param string $name
     * @param callable $encode
     * @param callable $decode
     *
     * @return void
     */
    static public function addFilter(string $name, callable $encode, callable $decode): void
    {
        self::$filters[$name] = [
            'encode' => $encode,
            'decode' => $decode,
        ];
    }

    /**
     * Disable Attribute decoding
     *
     * @return void
     */
    public static function disableFilters(): void
    {
        self::$statusFilters = false;
    }

    /**
     * Enable Attribute decoding
     *
     * @return void
     */
    public static function enableFilters(): void
    {
        self::$statusFilters = true;
    }

    public function encode(Document $document):Document
    {
        if (!self::$statusFilters) {
            return $document;
        }

        $collection = $this->getDocument($document->getCollection(), true , false);
        $rules = $collection->getAttribute('rules', []);

        foreach ($rules as $key => $rule) {
            $key = $rule->getAttribute('key', null);
            $type = $rule->getAttribute('type', null);
            $array = $rule->getAttribute('array', false);
            $filters = $rule->getAttribute('filter', []);
            $value = $document->getAttribute($key, null);

            if (($value !== null)) {
                if ($type === self::SYSTEM_VAR_TYPE_DOCUMENT) {
                    if($array) {
                        $list = [];
                        foreach ($value as $child) {
                            $list[] = $this->encode($child);
                        }

                        $document->setAttribute($key, $list);
                    } else {
                        $document->setAttribute($key, $this->encode($value));
                    }
                } else {
                    foreach ($filters as $filter) {
                        $value = $this->encodeAttribute($filter, $value);
                        $document->setAttribute($key, $value);
                    }
                }
            }
        }

        return $document;
    }

    public function decode(Document $document):Document
    {
        if (!self::$statusFilters) {
            return $document;
        }

        $collection = $this->getDocument($document->getCollection(), true , false);
        $rules = $collection->getAttribute('rules', []);

        foreach ($rules as $key => $rule) {
            $key = $rule->getAttribute('key', null);
            $type = $rule->getAttribute('type', null);
            $array = $rule->getAttribute('array', false);
            $filters = $rule->getAttribute('filter', []);
            $value = $document->getAttribute($key, null);

            if (($value !== null)) {
                if ($type === self::SYSTEM_VAR_TYPE_DOCUMENT) {
                    if($array) {
                        $list = [];
                        foreach ($value as $child) {
                            $list[] = $this->decode($child);
                        }

                        $document->setAttribute($key, $list);
                    } else {
                        $document->setAttribute($key, $this->decode($value));
                    }
                } else {
                    foreach (array_reverse($filters) as $filter) {
                        $value = $this->decodeAttribute($filter, $value);
                        $document->setAttribute($key, $value);
                    }
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
        if (!isset(self::$filters[$name])) {
            return $value;
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
        if (!isset(self::$filters[$name])) {
            return $value;
            throw new Exception('Filter not found');
        }

        try {
            $value = self::$filters[$name]['decode']($value);
        } catch (\Throwable $th) {
            $value = null;
        }

        return $value;
    }

    /**
     * Get Last Modified.
     *
     * Return Unix timestamp of last time a node queried in current session has been changed
     *
     * @return int
     */
    public function lastModified()
    {
        return $this->adapter->lastModified();
    }
}
