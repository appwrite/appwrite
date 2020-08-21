<?php

namespace Appwrite\Database\Adapter;

use Appwrite\Database\Adapter;
use Appwrite\Database\Database;
use Appwrite\Database\Exception\Duplicate;
use Appwrite\Database\Validator\Authorization;
use Exception;
use PDO;

class Relational extends Adapter
{
    /**
     * @var PDO
     */
    protected $pdo;

    /**
     * Constructor.
     *
     * Set connection and settings
     *
     * @param Registry $register
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Create Collection
     *
     * @param string $id
     *
     * @return bool
     */
    public function createCollection(string $id, array $attributes, array $indexs): bool
    {
        $columns = [];

        foreach ($attributes as $attribute) {
            $id = (isset($attribute['key'])) ? $attribute['key'] : '';
            $type = (isset($attribute['type'])) ? $attribute['type'] : '';
            $array = (isset($attribute['array'])) ? $attribute['array'] : false;

            if($array) {
                $this->createAttribute($id, $id, $type, true);
                continue;
            }

            $columns[] = $this->getColumn($id, $type);
        }

        $query = $this->getPDO()->prepare('CREATE TABLE `'.$this->getNamespace().'.collection.'.$id.'` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `uid` varchar(45) DEFAULT NULL,
            `collection` varchar(45) DEFAULT NULL,
            `createdAt` datetime DEFAULT NULL,
            `updatedAt` datetime DEFAULT NULL,
            `permissions` longtext DEFAULT NULL,
            '.implode("\n", $columns).'
            PRIMARY KEY (`id`),
            UNIQUE KEY `index1` (`uid`),
            KEY `index2` (`collection`),
            KEY `index3` (`uid`,`collection`)

          ) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4;
        ');

        if (!$query->execute()) {
            return false;
        }

        return true;
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
        // TODO fetch all array rules, and delete all child tables
        $query = $this->getPDO()->prepare('DROP TABLE `'.$this->getNamespace().'.collection.'.$id.'`;');

        if (!$query->execute()) {
            return false;
        }

        return true;
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
        if($array) {
            $query = $this->getPDO()->prepare('CREATE TABLE `'.$this->getNamespace().'.collection.'.$collection.'.'.$id.'` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `uid` varchar(45) DEFAULT NULL,
                '.$this->getColumn($id, $type).',
                PRIMARY KEY (`id`),
                KEY `index1` (`uid`)
              ) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4;
            ;');
        }
        else {
            $query = $this->getPDO()->prepare('ALTER TABLE `'.$this->getNamespace().'.collection.'.$collection.'`
                ADD COLUMN '.$this->getColumn($id, $type).';');
        }

        if (!$query->execute()) {
            return false;
        }

        return true;
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
    public function deleteAttribute(string $collection, string $id, bool $array = false): bool
    {
        if($array) {
            $query = $this->getPDO()->prepare('DROP TABLE `'.$this->getNamespace().'.collection.'.$collection.'.'.$id.'`;');
        }
        else {
            $query = $this->getPDO()->prepare('ALTER TABLE `'.$this->getNamespace().'.collection.'.$collection.'`
                DROP COLUMN `col_'.$id.'`;');
        }

        if (!$query->execute()) {
            return false;
        }

        return true;
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
        $columns = [];

        foreach ($attributes as $attribute) {
            $columns[] = '`col_'.$attribute.'`(32) ASC'; // TODO custom size limit per type
        }

        $index = '';

        switch ($type) {
            case Database::INDEX_KEY:
                $index = 'INDEX';
                break;

            case Database::INDEX_FULLTEXT:
                $index = 'FULLTEXT INDEX';
                break;

            case Database::INDEX_UNIQUE:
                $index = 'UNIQUE INDEX';
                break;

            case Database::INDEX_SPATIAL:
                $index = 'SPATIAL INDEX';
                break;
            
            default:
                throw new Exception('Unsupported indext type');
                break;
        }

        // TODO auto-index arrays?
        $query = $this->getPDO()->prepare('ALTER TABLE `'.$this->getNamespace().'.collection.'.$collection.'`
            ADD '.$index.' `index_'.$id.'` ('.implode(',', $columns).');');

        if (!$query->execute()) {
            return false;
        }

        return true;
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
        $query = $this->getPDO()->prepare('ALTER TABLE `'.$this->getNamespace().'.collection.'.$collection.'`
            DROP INDEX `index_'.$id.'`;');

        if (!$query->execute()) {
            return false;
        }

        return true;
    }

    /**
     * Get Document.
     *
     * @param string $collection
     * @param string $id
     *
     * @return array
     *
     * @throws Exception
     */
    public function getDocument($collection, $id)
    {
        // Get fields abstraction
        $st = $this->getPDO()->prepare('SELECT * FROM `'.$this->getNamespace().'.collection.'.$collection.'` documents
            WHERE documents.uid = :uid;
        ');

        $st->bindValue(':uid', $id, PDO::PARAM_STR);

        $st->execute();

        $document = $st->fetch();

        if (empty($document)) { // Not Found
            return [];
        }

        return $document;
    }

    /**
     * Create Document.
     *
     * @param string $collection
     * @param array $data
     * @param array $unique
     *
     * @throws \Exception
     *
     * @return array
     */
    public function createDocument(string $collection, array $data, array $unique = [])
    {
        $data['$id'] = $this->getId();
        $data['$permissions'] = (!isset($data['$permissions'])) ? [] : $data['$permissions'];
        
        // $collection = $this->getDocument(Database::COLLECTION_COLLECTIONS, $collection);
        // $rules = (isset($collection['rules'])) ? $collection['rules'] : [];

        // if(empty($collection)) {
        //     throw new Exception('Missing collection data');
        // }

        $rules = [];

        /**
         * Check Unique Keys
         */
        throw new Duplicate('Duplicated Property');
        
        // Add or update fields abstraction level
        $st = $this->getPDO()->prepare('INSERT INTO  `'.$this->getNamespace().'.collection.'.$collection.'`
            SET uid = :uid, createdAt = :createdAt, updatedAt = :updatedAt, permissions = :permissions;
        ');

        $st->bindValue(':uid', $data['$id'], PDO::PARAM_STR);
        $st->bindValue(':createdAt', \date('Y-m-d H:i:s', \time()), PDO::PARAM_STR);
        $st->bindValue(':updatedAt', \date('Y-m-d H:i:s', \time()), PDO::PARAM_STR);
        $st->bindValue(':permissions', \json_encode($data['$permissions']), PDO::PARAM_STR);
        
        foreach ($rules as $rule) {
            $rule['type'] = (isset($rule['type'])) ? $rule['type'] : '';
            $rule['key'] = (isset($rule['key'])) ? $rule['key'] : '';
            $type = '';
            $value = isset($data[$rule['key']]) ? $data[$rule['key']] : null;
            
            switch ($rule['type']) {
                case Database::VAR_TEXT:
                case Database::VAR_URL:
                case Database::VAR_KEY:
                case Database::VAR_DOCUMENT:
                case Database::VAR_EMAIL:
                    $type = PDO::PARAM_STR;
                    break;

                case Database::VAR_IPV4:
                    $type = PDO::PARAM_INT;
                    break;

                case Database::VAR_IPV6:
                    $type = PDO::PARAM_LOB;
                    $value = hex2bin($value);
                    break;

                case Database::VAR_INTEGER:
                    $type = PDO::PARAM_INT;
                    break;
                
                case Database::VAR_FLOAT:
                case Database::VAR_NUMERIC:
                    $type = PDO::PARAM_STR;
                    break;

                case Database::VAR_BOOLEAN:
                    $type = PDO::PARAM_BOOL;
                    break;

                default:
                    throw new Exception('Unsupported attribute');
                    break;
            }

            $st->bindValue(':col_'.$rule['key'], $value, $type);
        }

        $st->execute();

        //TODO remove this dependency (check if related to nested documents)
        // $this->getRedis()->expire($this->getNamespace().':document-'.$data['$id'], 0);
        // $this->getRedis()->expire($this->getNamespace().':document-'.$data['$id'], 0);

        return $data;
    }

    /**
     * Update Document.
     *
     * @param string $collection
     * @param string $id
     * @param array $data
     *
     * @return array
     *
     * @throws Exception
     */
    public function updateDocument(string $collection, string $id, array $data)
    {
        return $this->createDocument($collection, $data);
    }

    /**
     * Delete Document.
     *
     * @param string $collection
     * @param string $id
     *
     * @return array
     *
     * @throws Exception
     */
    public function deleteDocument(string $collection, string $id)
    {
        $st1 = $this->getPDO()->prepare('DELETE FROM `'.$this->getNamespace().'.database.documents`
            WHERE uid = :id
		');

        $st1->bindValue(':id', $id, PDO::PARAM_STR);

        $st1->execute();

        $st2 = $this->getPDO()->prepare('DELETE FROM `'.$this->getNamespace().'.database.properties`
            WHERE documentUid = :id
		');

        $st2->bindValue(':id', $id, PDO::PARAM_STR);

        $st2->execute();

        $st3 = $this->getPDO()->prepare('DELETE FROM `'.$this->getNamespace().'.database.relationships`
            WHERE start = :id OR end = :id
		');

        $st3->bindValue(':id', $id, PDO::PARAM_STR);

        $st3->execute();

        return [];
    }

    /**
     * Create Relation.
     *
     * Adds a new relationship between different nodes
     *
     * @param string $revision
     * @param int    $start
     * @param int    $end
     * @param string $key
     * @param bool   $isArray
     * @param int    $order
     *
     * @return array
     *
     * @throws Exception
     */
    protected function createRelationship($revision, $start, $end, $key, $isArray = false, $order = 0)
    {
        $st2 = $this->getPDO()->prepare('INSERT INTO `'.$this->getNamespace().'.database.relationships`
                (`revision`, `start`, `end`, `key`, `array`, `order`)
            VALUES (:revision, :start, :end, :key, :array, :order)');

        $st2->bindValue(':revision', $revision, PDO::PARAM_STR);
        $st2->bindValue(':start', $start, PDO::PARAM_STR);
        $st2->bindValue(':end', $end, PDO::PARAM_STR);
        $st2->bindValue(':key', $key, PDO::PARAM_STR);
        $st2->bindValue(':array', $isArray, PDO::PARAM_INT);
        $st2->bindValue(':order', $order, PDO::PARAM_INT);

        $st2->execute();

        return [];
    }

    /**
     * Create Namespace.
     *
     * @param $namespace
     *
     * @throws Exception
     *
     * @return bool
     */
    public function createNamespace($namespace)
    {
        if (empty($namespace)) {
            throw new Exception('Empty namespace');
        }

        $documents = 'app_'.$namespace.'.database.documents';
        $properties = 'app_'.$namespace.'.database.properties';
        $relationships = 'app_'.$namespace.'.database.relationships';
        $unique = 'app_'.$namespace.'.database.unique';
        $audit = 'app_'.$namespace.'.audit.audit';
        $abuse = 'app_'.$namespace.'.abuse.abuse';

        /**
         * 1. Itterate default collections
         * 2. Create collection
         * 3. Create all regular and array fields
         * 4. Create all indexes
         * 5. Create audit / abuse tables
         */

        try {
            $this->getPDO()->prepare('CREATE TABLE `'.$documents.'` LIKE `template.database.documents`;')->execute();
            $this->getPDO()->prepare('CREATE TABLE `'.$properties.'` LIKE `template.database.properties`;')->execute();
            $this->getPDO()->prepare('CREATE TABLE `'.$relationships.'` LIKE `template.database.relationships`;')->execute();
            $this->getPDO()->prepare('CREATE TABLE `'.$unique.'` LIKE `template.database.unique`;')->execute();
            $this->getPDO()->prepare('CREATE TABLE `'.$audit.'` LIKE `template.audit.audit`;')->execute();
            $this->getPDO()->prepare('CREATE TABLE `'.$abuse.'` LIKE `template.abuse.abuse`;')->execute();
        } catch (Exception $e) {
            throw $e;
        }

        return true;
    }

    /**
     * Delete Namespace.
     *
     * @param $namespace
     *
     * @throws Exception
     *
     * @return bool
     */
    public function deleteNamespace($namespace)
    {
        if (empty($namespace)) {
            throw new Exception('Empty namespace');
        }

        $unique = 'app_'.$namespace.'.database.unique';
        $documents = 'app_'.$namespace.'.database.documents';
        $properties = 'app_'.$namespace.'.database.properties';
        $relationships = 'app_'.$namespace.'.database.relationships';
        $audit = 'app_'.$namespace.'.audit.audit';
        $abuse = 'app_'.$namespace.'.abuse.abuse';

        try {
            $this->getPDO()->prepare('DROP TABLE `'.$unique.'`;')->execute();
            $this->getPDO()->prepare('DROP TABLE `'.$documents.'`;')->execute();
            $this->getPDO()->prepare('DROP TABLE `'.$properties.'`;')->execute();
            $this->getPDO()->prepare('DROP TABLE `'.$relationships.'`;')->execute();
            $this->getPDO()->prepare('DROP TABLE `'.$audit.'`;')->execute();
            $this->getPDO()->prepare('DROP TABLE `'.$abuse.'`;')->execute();
        } catch (Exception $e) {
            throw $e;
        }

        return true;
    }

    /**
     * Get Collection.
     *
     * @param array $options
     *
     * @throws Exception
     *
     * @return array
     */
    public function find(array $options)
    {
        $start = \microtime(true);
        $orderCastMap = [
            'int' => 'UNSIGNED',
            'string' => 'CHAR',
            'date' => 'DATE',
            'time' => 'TIME',
            'datetime' => 'DATETIME',
        ];
        $orderTypeMap = ['DESC', 'ASC'];

        $options['orderField'] = (empty($options['orderField'])) ? '$id' : $options['orderField']; // Set default order field
        $options['orderCast'] = (empty($options['orderCast'])) ? 'string' : $options['orderCast']; // Set default order field

        if (!\array_key_exists($options['orderCast'], $orderCastMap)) {
            throw new Exception('Invalid order cast');
        }

        if (!\in_array($options['orderType'], $orderTypeMap)) {
            throw new Exception('Invalid order type');
        }

        $where = [];
        $join = [];
        $sorts = [];
        $search = '';

        // Filters
        foreach ($options['filters'] as $i => $filter) {
            $filter = $this->parseFilter($filter);
            $key = $filter['key'];
            $value = $filter['value'];
            $operator = $filter['operator'];

            $path = \explode('.', $key);
            $original = $path;

            if (1 < \count($path)) {
                $key = \array_pop($path);
            } else {
                $path = [];
            }

            //$path = implode('.', $path);

            $key = $this->getPDO()->quote($key, PDO::PARAM_STR);
            $value = $this->getPDO()->quote($value, PDO::PARAM_STR);
            //$path               = $this->getPDO()->quote($path, PDO::PARAM_STR);
            $options['offset'] = (int) $options['offset'];
            $options['limit'] = (int) $options['limit'];

            if (empty($path)) {
                //if($path == "''") { // Handle direct attributes queries
                $where[] = 'JOIN `'.$this->getNamespace().".database.properties` b{$i} ON a.uid IS NOT NULL AND b{$i}.documentUid = a.uid AND (b{$i}.key = {$key} AND b{$i}.value {$operator} {$value})";
            } else { // Handle direct child attributes queries
                $len = \count($original);
                $prev = 'c'.$i;

                foreach ($original as $y => $part) {
                    $part = $this->getPDO()->quote($part, PDO::PARAM_STR);

                    if (0 === $y) { // First key
                        $join[$i] = 'JOIN `'.$this->getNamespace().".database.relationships` c{$i} ON a.uid IS NOT NULL AND c{$i}.start = a.uid AND c{$i}.key = {$part}";
                    } elseif ($y == $len - 1) { // Last key
                        $join[$i] .= 'JOIN `'.$this->getNamespace().".database.properties` e{$i} ON e{$i}.documentUid = {$prev}.end AND e{$i}.key = {$part} AND e{$i}.value {$operator} {$value}";
                    } else {
                        $join[$i] .= 'JOIN `'.$this->getNamespace().".database.relationships` d{$i}{$y} ON d{$i}{$y}.start = {$prev}.end AND d{$i}{$y}.key = {$part}";
                        $prev = 'd'.$i.$y;
                    }
                }

                //$join[] = "JOIN `" . $this->getNamespace() . ".database.relationships` c{$i} ON a.uid IS NOT NULL AND c{$i}.start = a.uid AND c{$i}.key = {$path}
                //    JOIN `" . $this->getNamespace() . ".database.properties` d{$i} ON d{$i}.documentUid = c{$i}.end AND d{$i}.key = {$key} AND d{$i}.value {$operator} {$value}";
            }
        }

        // Sorting
        $orderPath = \explode('.', $options['orderField']);
        $len = \count($orderPath);
        $orderKey = 'order_b';
        $part = $this->getPDO()->quote(\implode('', $orderPath), PDO::PARAM_STR);
        $orderSelect = "CASE WHEN {$orderKey}.key = {$part} THEN CAST({$orderKey}.value AS {$orderCastMap[$options['orderCast']]}) END AS sort_ff";

        if (1 === $len) {
            //if($path == "''") { // Handle direct attributes queries
            $sorts[] = 'LEFT JOIN `'.$this->getNamespace().".database.properties` order_b ON a.uid IS NOT NULL AND order_b.documentUid = a.uid AND (order_b.key = {$part})";
        } else { // Handle direct child attributes queries
            $prev = 'c';
            $orderKey = 'order_e';

            foreach ($orderPath as $y => $part) {
                $part = $this->getPDO()->quote($part, PDO::PARAM_STR);
                $x = $y - 1;

                if (0 === $y) { // First key
                    $sorts[] = 'JOIN `'.$this->getNamespace().".database.relationships` order_c{$y} ON a.uid IS NOT NULL AND order_c{$y}.start = a.uid AND order_c{$y}.key = {$part}";
                } elseif ($y == $len - 1) { // Last key
                    $sorts[] .= 'JOIN `'.$this->getNamespace().".database.properties` order_e ON order_e.documentUid = order_{$prev}{$x}.end AND order_e.key = {$part}";
                } else {
                    $sorts[] .= 'JOIN `'.$this->getNamespace().".database.relationships` order_d{$y} ON order_d{$y}.start = order_{$prev}{$x}.end AND order_d{$y}.key = {$part}";
                    $prev = 'd';
                }
            }
        }

        /*
         * Workaround for a MySQL bug as reported here:
         * https://bugs.mysql.com/bug.php?id=78485
         */
        $options['search'] = ($options['search'] === '*') ? '' : $options['search'];

        // Search
        if (!empty($options['search'])) { // Handle free search
            $where[] = 'LEFT JOIN `'.$this->getNamespace().".database.properties` b_search ON a.uid IS NOT NULL AND b_search.documentUid = a.uid  AND b_search.primitive = 'string'
                    LEFT JOIN
                `".$this->getNamespace().'.database.relationships` c_search ON c_search.start = b_search.documentUid
                    LEFT JOIN
                `'.$this->getNamespace().".database.properties` d_search ON d_search.documentUid = c_search.end AND d_search.primitive = 'string'
                \n";

            $search = "AND (MATCH (b_search.value) AGAINST ({$this->getPDO()->quote($options['search'], PDO::PARAM_STR)} IN BOOLEAN MODE)
                OR MATCH (d_search.value) AGAINST ({$this->getPDO()->quote($options['search'], PDO::PARAM_STR)} IN BOOLEAN MODE)
            )";
        }

        $select = 'DISTINCT a.uid';
        $where = \implode("\n", $where);
        $join = \implode("\n", $join);
        $sorts = \implode("\n", $sorts);
        $range = "LIMIT {$options['offset']}, {$options['limit']}";
        $roles = [];

        foreach (Authorization::getRoles() as $role) {
            $roles[] = 'JSON_CONTAINS(REPLACE(a.permissions, \'{self}\', a.uid), \'"'.$role.'"\', \'$.read\')';
        }

        if (false === Authorization::$status) { // FIXME temporary solution (hopefully)
            $roles = ['1=1'];
        }

        $query = "SELECT %s, {$orderSelect}
            FROM `".$this->getNamespace().".database.documents` a {$where}{$join}{$sorts}
            WHERE status = 0
               {$search}
               AND (".\implode('||', $roles).")
            ORDER BY sort_ff {$options['orderType']} %s";

        $st = $this->getPDO()->prepare(\sprintf($query, $select, $range));

        $st->execute();

        $results = ['data' => []];

        // Get entire fields data for each id
        foreach ($st->fetchAll() as $node) {
            $results['data'][] = $node['uid'];
        }

        $count = $this->getPDO()->prepare(\sprintf($query, 'count(DISTINCT a.uid) as sum', ''));

        $count->execute();

        $count = $count->fetch();

        $this->resetDebug();

        $this
            ->setDebug('query', \preg_replace('/\s+/', ' ', \sprintf($query, $select, $range)))
            ->setDebug('time', \microtime(true) - $start)
            ->setDebug('filters', \count($options['filters']))
            ->setDebug('joins', \substr_count($query, 'JOIN'))
            ->setDebug('count', \count($results['data']))
            ->setDebug('sum', (int) $count['sum'])
        ;

        return $results['data'];
    }

    /**
     * Get Collection.
     *
     * @param array $options
     *
     * @throws Exception
     *
     * @return int
     */
    public function count(array $options)
    {
        $start = \microtime(true);
        $where = [];
        $join = [];

        $options = array_merge([
            'attribute' => '',
            'filters' => [],
        ], $options);

        // Filters
        foreach ($options['filters'] as $i => $filter) {
            $filter = $this->parseFilter($filter);
            $key = $filter['key'];
            $value = $filter['value'];
            $operator = $filter['operator'];
            $path = \explode('.', $key);
            $original = $path;

            if (1 < \count($path)) {
                $key = \array_pop($path);
            } else {
                $path = [];
            }

            $key = $this->getPDO()->quote($key, PDO::PARAM_STR);
            $value = $this->getPDO()->quote($value, PDO::PARAM_STR);

            if (empty($path)) {
                //if($path == "''") { // Handle direct attributes queries
                $where[] = 'JOIN `'.$this->getNamespace().".database.properties` b{$i} ON a.uid IS NOT NULL AND b{$i}.documentUid = a.uid AND (b{$i}.key = {$key} AND b{$i}.value {$operator} {$value})";
            } else { // Handle direct child attributes queries
                $len = \count($original);
                $prev = 'c'.$i;

                foreach ($original as $y => $part) {
                    $part = $this->getPDO()->quote($part, PDO::PARAM_STR);

                    if (0 === $y) { // First key
                        $join[$i] = 'JOIN `'.$this->getNamespace().".database.relationships` c{$i} ON a.uid IS NOT NULL AND c{$i}.start = a.uid AND c{$i}.key = {$part}";
                    } elseif ($y == $len - 1) { // Last key
                        $join[$i] .= 'JOIN `'.$this->getNamespace().".database.properties` e{$i} ON e{$i}.documentUid = {$prev}.end AND e{$i}.key = {$part} AND e{$i}.value {$operator} {$value}";
                    } else {
                        $join[$i] .= 'JOIN `'.$this->getNamespace().".database.relationships` d{$i}{$y} ON d{$i}{$y}.start = {$prev}.end AND d{$i}{$y}.key = {$part}";
                        $prev = 'd'.$i.$y;
                    }
                }
            }
        }

        $where = \implode("\n", $where);
        $join = \implode("\n", $join);
        $attribute = $this->getPDO()->quote($options['attribute'], PDO::PARAM_STR);
        $func = 'JOIN `'.$this->getNamespace().".database.properties` b_func ON a.uid IS NOT NULL
            AND a.uid = b_func.documentUid
            AND (b_func.key = {$attribute})";
        $roles = [];

        foreach (Authorization::getRoles() as $role) {
            $roles[] = 'JSON_CONTAINS(REPLACE(a.permissions, \'{self}\', a.uid), \'"'.$role.'"\', \'$.read\')';
        }

        if (false === Authorization::$status) { // FIXME temporary solution (hopefully)
            $roles = ['1=1'];
        }

        $query = "SELECT SUM(b_func.value) as result
            FROM `".$this->getNamespace().".database.documents` a {$where}{$join}{$func}
            WHERE status = 0
               AND (".\implode('||', $roles).')';

        $st = $this->getPDO()->prepare(\sprintf($query));

        $st->execute();

        $result = $st->fetch();

        $this->resetDebug();

        $this
            ->setDebug('query', \preg_replace('/\s+/', ' ', \sprintf($query)))
            ->setDebug('time', \microtime(true) - $start)
            ->setDebug('filters', \count($options['filters']))
            ->setDebug('joins', \substr_count($query, 'JOIN'))
        ;

        return (isset($result['result'])) ? (int)$result['result'] : 0;
    }

    /**
     * Parse Filter.
     *
     * @param string $filter
     *
     * @return array
     *
     * @throws Exception
     */
    protected function parseFilter($filter)
    {
        $operatorsMap = ['!=', '>=', '<=', '=', '>', '<']; // Do not edit order of this array

        //FIXME bug with >= <= operators

        $operator = null;

        foreach ($operatorsMap as $node) {
            if (\strpos($filter, $node) !== false) {
                $operator = $node;
                break;
            }
        }

        if (empty($operator)) {
            throw new Exception('Invalid operator');
        }

        $filter = \explode($operator, $filter);

        if (\count($filter) != 2) {
            throw new Exception('Invalid filter expression');
        }

        return [
            'key' => $filter[0],
            'value' => $filter[1],
            'operator' => $operator,
        ];
    }

    /**
     * Get Data Type.
     *
     * Check value data type. return value can be on of the following:
     * string, integer, float, boolean, object, list or null
     *
     * @param $value
     *
     * @return string
     *
     * @throws \Exception
     */
    protected function getDataType($value)
    {
        switch (\gettype($value)) {

            case 'string':
                return self::DATA_TYPE_STRING;
                break;

            case 'integer':
                return self::DATA_TYPE_INTEGER;
                break;

            case 'double':
                return self::DATA_TYPE_FLOAT;
                break;

            case 'boolean':
                return self::DATA_TYPE_BOOLEAN;
                break;

            case 'array':
                if ((bool) \count(\array_filter(\array_keys($value), 'is_string'))) {
                    return self::DATA_TYPE_DICTIONARY;
                }

                return self::DATA_TYPE_ARRAY;
                break;

            case 'NULL':
                return self::DATA_TYPE_NULL;
                break;
        }

        throw new Exception('Unknown data type: '.$value.' ('.\gettype($value).')');
    }

    /**
     * Get Column
     * 
     * @var string $key
     * @var string $type
     * 
     * @return string
     */
    protected function getColumn(string $key, string $type): string
    {
        switch ($type) {
            case Database::VAR_TEXT:
            case Database::VAR_URL:
                return '`col_'.$key.'` TEXT NULL';
                break;

            case Database::VAR_KEY:
            case Database::VAR_DOCUMENT:
                return '`col_'.$key.'` VARCHAR(36) NULL';
                break;

            case Database::VAR_IPV4:
                return '`col_'.$key.'` INT UNSIGNED NULL';
                break;

            case Database::VAR_IPV6:
                return '`col_'.$key.'` BINARY(16) NULL';
                break;

            case Database::VAR_EMAIL:
                return '`col_'.$key.'` VARCHAR(255) NULL';
                break;

            case Database::VAR_INTEGER:
                return '`col_'.$key.'` INT NULL';
                break;
            
            case Database::VAR_FLOAT:
            case Database::VAR_NUMERIC:
                return '`col_'.$key.'` FLOAT NULL';
                break;

            case Database::VAR_BOOLEAN:
                return '`col_'.$key.'` BOOLEAN NULL';
                break;

            default:
                throw new Exception('Unsupported attribute type');
                break;
        }
    }

    /**
     * @return PDO
     *
     * @throws Exception
     */
    protected function getPDO()
    {
        return $this->pdo;
    }
}
