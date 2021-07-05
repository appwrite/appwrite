<?php

namespace Appwrite\Database\Adapter;

use Appwrite\Database\Adapter;
use Appwrite\Database\Exception\Duplicate;
use Appwrite\Database\Validator\Authorization;
use Exception;
use PDO;
use Redis;

class MySQL extends Adapter
{
    const DATA_TYPE_STRING = 'string';
    const DATA_TYPE_INTEGER = 'integer';
    const DATA_TYPE_FLOAT = 'float';
    const DATA_TYPE_BOOLEAN = 'boolean';
    const DATA_TYPE_OBJECT = 'object';
    const DATA_TYPE_DICTIONARY = 'dictionary';
    const DATA_TYPE_ARRAY = 'array';
    const DATA_TYPE_NULL = 'null';

    const OPTIONS_LIMIT_ATTRIBUTES = 1000;

    /**
     * Last modified.
     *
     * Read node with most recent changes
     *
     * @var int
     */
    protected $lastModified = -1;

    /**
     * @var array
     */
    protected $debug = [];

    /**
     * @var PDO
     */
    protected $pdo;

    /**
     * @var Redis
     */
    protected $redis;

    /**
     * Constructor.
     *
     * Set connection and settings
     *
     * @param PDO $pdo
     * @param Redis $redis
     */
    public function __construct($pdo, Redis $redis)
    {
        $this->pdo = $pdo;
        $this->redis = $redis;
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
        // Get fields abstraction
        $st = $this->getPDO()->prepare('SELECT * FROM `'.$this->getNamespace().'.database.documents` a
            WHERE a.uid = :uid AND a.status = 0
            ORDER BY a.updatedAt DESC LIMIT 10;
        ');

        $st->bindValue(':uid', $id, PDO::PARAM_STR);

        $st->execute();

        $document = $st->fetch();

        if (empty($document)) { // Not Found
            return [];
        }

        // Get fields abstraction
        $st = $this->getPDO()->prepare('SELECT * FROM `'.$this->getNamespace().'.database.properties` a
            WHERE a.documentUid = :documentUid AND a.documentRevision = :documentRevision
              ORDER BY `order`
        ');

        $st->bindParam(':documentUid', $document['uid'], PDO::PARAM_STR, 32);
        $st->bindParam(':documentRevision', $document['revision'], PDO::PARAM_STR, 32);

        $st->execute();

        $properties = $st->fetchAll();

        $output = [
            '$id' => null,
            '$collection' => null,
            '$permissions' => (!empty($document['permissions'])) ? \json_decode($document['permissions'], true) : [],
        ];

        foreach ($properties as &$property) {
            \settype($property['value'], $property['primitive']);

            if ($property['array']) {
                $output[$property['key']][] = $property['value'];
            } else {
                $output[$property['key']] = $property['value'];
            }
        }

        // Get fields abstraction
        $st = $this->getPDO()->prepare('SELECT * FROM `'.$this->getNamespace().'.database.relationships` a
            WHERE a.start = :start AND revision = :revision
              ORDER BY `order`
        ');

        $st->bindParam(':start', $document['uid'], PDO::PARAM_STR, 32);
        $st->bindParam(':revision', $document['revision'], PDO::PARAM_STR, 32);

        $st->execute();

        $output['temp-relations'] = $st->fetchAll();

        return $output;
    }

    /**
     * Create Document.
     *
     * @param array $data
     *
     * @throws \Exception
     *
     * @return array
     */
    public function createDocument(array $data = [], array $unique = [])
    {
        $order = 0;
        $data = \array_merge(['$id' => null, '$permissions' => []], $data); // Merge data with default params
        $signature = \md5(\json_encode($data));
        $revision = \uniqid('', true);
        $data['$id'] = (empty($data['$id'])) ? null : $data['$id'];

        /*
         * When updating node, check if there are any changes to update
         *  by comparing data md5 signatures
         */
        if (null !== $data['$id']) {
            $st = $this->getPDO()->prepare('SELECT signature FROM `'.$this->getNamespace().'.database.documents` a
                    WHERE a.uid = :uid AND a.status = 0
                    ORDER BY a.updatedAt DESC LIMIT 1;
                ');

            $st->bindValue(':uid', $data['$id'], PDO::PARAM_STR);

            $st->execute();

            $result = $st->fetch();

            if ($result && isset($result['signature'])) {
                $oldSignature = $result['signature'];

                if ($signature === $oldSignature) {
                    return $data;
                }
            }
        }

        /**
         * Check Unique Keys
         */
        foreach ($unique as $key => $value) {
            $st = $this->getPDO()->prepare('INSERT INTO `'.$this->getNamespace().'.database.unique`
                SET `key` = :key;
            ');
            
            $st->bindValue(':key', \md5($data['$collection'].':'.$key.'='.$value), PDO::PARAM_STR);

            if (!$st->execute()) {
                throw new Duplicate('Duplicated Property: '.$key.'='.$value);
            }
        }
        
        // Add or update fields abstraction level
        $st1 = $this->getPDO()->prepare('INSERT INTO `'.$this->getNamespace().'.database.documents`
            SET uid = :uid, createdAt = :createdAt, updatedAt = :updatedAt, signature = :signature, revision = :revision, permissions = :permissions, status = 0
            ON DUPLICATE KEY UPDATE uid = :uid, updatedAt = :updatedAt, signature = :signature, revision = :revision, permissions = :permissions;
		');

        // Adding fields properties
        if (null === $data['$id'] || !isset($data['$id'])) { // Get new fields UID
            $data['$id'] = $this->getId();
        }

        $st1->bindValue(':uid', $data['$id'], PDO::PARAM_STR);
        $st1->bindValue(':revision', $revision, PDO::PARAM_STR);
        $st1->bindValue(':signature', $signature, PDO::PARAM_STR);
        $st1->bindValue(':createdAt', \date('Y-m-d H:i:s', \time()), PDO::PARAM_STR);
        $st1->bindValue(':updatedAt', \date('Y-m-d H:i:s', \time()), PDO::PARAM_STR);
        $st1->bindValue(':permissions', \json_encode($data['$permissions']), PDO::PARAM_STR);

        $st1->execute();

        // Delete old properties
        $rms1 = $this->getPDO()->prepare('DELETE FROM `'.$this->getNamespace().'.database.properties` WHERE documentUid = :documentUid AND documentRevision != :documentRevision');
        $rms1->bindValue(':documentUid', $data['$id'], PDO::PARAM_STR);
        $rms1->bindValue(':documentRevision', $revision, PDO::PARAM_STR);
        $rms1->execute();

        // Delete old relationships
        $rms2 = $this->getPDO()->prepare('DELETE FROM `'.$this->getNamespace().'.database.relationships` WHERE start = :start AND revision != :revision');
        $rms2->bindValue(':start', $data['$id'], PDO::PARAM_STR);
        $rms2->bindValue(':revision', $revision, PDO::PARAM_STR);
        $rms2->execute();

        // Create new properties
        $st2 = $this->getPDO()->prepare('INSERT INTO `'.$this->getNamespace().'.database.properties`
                    (`documentUid`, `documentRevision`, `key`, `value`, `primitive`, `array`, `order`)
                VALUES (:documentUid, :documentRevision, :key, :value, :primitive, :array, :order)');

        $props = [];

        foreach ($data as $key => $value) { // Prepare properties data

            if (\in_array($key, ['$permissions'])) {
                continue;
            }

            $type = $this->getDataType($value);

            // Handle array of relations
            if (self::DATA_TYPE_ARRAY === $type) {
                if (!is_array($value)) { // Property should be of type array, if not = skip
                    continue;
                }

                foreach ($value as $i => $child) {
                    if (self::DATA_TYPE_DICTIONARY !== $this->getDataType($child)) { // not dictionary

                        $props[] = [
                            'type' => $this->getDataType($child),
                            'key' => $key,
                            'value' => $child,
                            'array' => true,
                            'order' => $order++,
                        ];

                        continue;
                    }

                    $data[$key][$i] = $this->createDocument($child);

                    $this->createRelationship($revision, $data['$id'], $data[$key][$i]['$id'], $key, true, $i);
                }

                continue;
            }

            // Handle relation
            if (self::DATA_TYPE_DICTIONARY === $type) {
                $value = $this->createDocument($value);
                $this->createRelationship($revision, $data['$id'], $value['$id'], $key); //xxx
                continue;
            }

            // Handle empty values
            if (self::DATA_TYPE_NULL === $type) {
                continue;
            }

            $props[] = [
                'type' => $type,
                'key' => $key,
                'value' => $value,
                'array' => false,
                'order' => $order++,
            ];
        }

        foreach ($props as $prop) {
            if (\is_array($prop['value'])) {
                throw new Exception('Value can\'t be an array: '.\json_encode($prop['value']));
            }
            if (\is_bool($prop['value'])) {
                $prop['value'] = (int) $prop['value'];
            }
            $st2->bindValue(':documentUid', $data['$id'], PDO::PARAM_STR);
            $st2->bindValue(':documentRevision', $revision, PDO::PARAM_STR);

            $st2->bindValue(':key', $prop['key'], PDO::PARAM_STR);
            $st2->bindValue(':value', $prop['value'], PDO::PARAM_STR);
            $st2->bindValue(':primitive', $prop['type'], PDO::PARAM_STR);
            $st2->bindValue(':array', $prop['array'], PDO::PARAM_BOOL);
            $st2->bindValue(':order', $prop['order'], PDO::PARAM_STR);

            $st2->execute();
        }

        //TODO remove this dependency (check if related to nested documents)
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
        return $this->createDocument($data);
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
     * Delete Unique Key.
     *
     * @param string $key
     *
     * @return array
     *
     * @throws Exception
     */
    public function deleteUniqueKey($key)
    {
        $st1 = $this->getPDO()->prepare('DELETE FROM `'.$this->getNamespace().'.database.unique` WHERE `key` = :key');

        $st1->bindValue(':key', $key, PDO::PARAM_STR);

        $st1->execute();

        return [];
    }

    /**
     * Add Unique Key.
     *
     * @param string $key
     *
     * @return array
     *
     * @throws Exception
     */
    public function addUniqueKey($key)
    {
        $st = $this->getPDO()->prepare('INSERT INTO `'.$this->getNamespace().'.database.unique`
        SET `key` = :key;
        ');
    
        $st->bindValue(':key', $key, PDO::PARAM_STR);

        if (!$st->execute()) {
            throw new Duplicate('Duplicated Property: '.$key);
        }

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
     * @param array $filterTypes
     *
     * @throws Exception
     *
     * @return array
     */
    public function getCollection(array $options, array $filterTypes = [])
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

        $options['orderField'] = (empty($options['orderField'])) ? '' : $options['orderField']; // Set default order field
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

            $castToInt = array_key_exists($key, $filterTypes) && $filterTypes[$key] === 'numeric';

            $key = $this->getPDO()->quote($key, PDO::PARAM_STR);
            $value = $this->getPDO()->quote($value, PDO::PARAM_STR);

            if ($castToInt) {
                $value .= '+0';
            }

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
        if(!empty($options['orderField'])) {
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
        }
        else {
            $orderSelect = 'a.uid AS sort_ff';
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
        var_dump(\sprintf($query, $select, $range));
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
    public function getCount(array $options)
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
     * Get Unique Document ID.
     *
     * @return string
     */
    public function getId(): string
    {
        $unique = \uniqid();
        $attempts = 5;

        for ($i = 1; $i <= $attempts; ++$i) {
            $document = $this->getDocument($unique);

            if (empty($document) || $document['$id'] !== $unique) {
                return $unique;
            }
        }

        throw new Exception('Failed to create a unique ID ('.$attempts.' attempts)');
    }

    /**
     * Last Modified.
     *
     * Return Unix timestamp of last time a node queried in corrent session has been changed
     *
     * @return int
     */
    public function lastModified()
    {
        return $this->lastModified;
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
     * @param string $key
     * @param mixed $value
     *
     * @return $this
     */
    public function setDebug(string $key, $value): self
    {
        $this->debug[$key] = $value;

        return $this;
    }

    /**
     * @return array
     */
    public function getDebug(): array
    {
        return $this->debug;
    }

    /**
     * return $this;.
     *
     * @return void
     */
    public function resetDebug(): void
    {
        $this->debug = [];
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

    /**
     * @throws Exception
     *
     * @return Redis
     */
    protected function getRedis(): Redis
    {
        return $this->redis;
    }
}