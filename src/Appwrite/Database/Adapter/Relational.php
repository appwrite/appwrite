<?php

namespace Appwrite\Database\Adapter;

use Appwrite\Database\Adapter;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Exception\Duplicate;
use Appwrite\Database\Validator\Authorization;
use Exception;
use PDO;
use stdClass;

class Relational extends Adapter
{
    /**
     * @var PDO
     */
    protected $pdo;

    /**
     * @var array
     */
    protected $protected = [
        '$id' => true,
        '$collection' => true,
        '$permissions' => true
    ];

    /**
     * @var bool
     */
    protected $transaction = false;

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
     * @param Document $collection
     * @param string $id
     *
     * @return bool
     */
    public function createCollection(Document $collection, string $id): bool
    {
        if($collection->isEmpty()) {
            throw new Exception('Missing Collection');
        }

        $rules = $collection->getAttribute('rules', []);
        $indexes = $collection->getAttribute('indexes', []);
        $PDOColumns = [];
        $PDOIndexes = [];

        foreach ($rules as $rule) { /** @var Document $attribute */
            $key = $rule->getAttribute('key');
            $type = $rule->getAttribute('type');
            $array = $rule->getAttribute('array');

            if($array) {
                $this->createAttribute($collection, $key, $type, $array);
                continue;
            }

            $PDOColumns[] = $this->getAttributeType($key, $type);
        }

        foreach ($indexes as $index) { /** @var Document $index */
            $type = $index->getAttribute('type', '');
            $attributes = $index->getAttribute('attributes', []);

            $PDOIndexes[] = $this->getIndexType($index->getId(), $type, $attributes);
        }

        $PDOColumns = (!empty($PDOColumns)) ? implode(",\n", $PDOColumns) . ",\n" : '';
        $PDOIndexes = (!empty($PDOIndexes)) ? ",\n" . implode(",\n", $PDOIndexes) : '';

        $query = $this->getPDO()->prepare('CREATE TABLE `app_'.$this->getNamespace().'.collection.'.$id.'` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `uid` varchar(45) DEFAULT NULL,
            `createdAt` datetime DEFAULT NULL,
            `updatedAt` datetime DEFAULT NULL,
            `permissions` longtext DEFAULT NULL,
            '.$PDOColumns.'
            PRIMARY KEY (`id`),
            UNIQUE KEY `index1` (`uid`)
            '.$PDOIndexes.'
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
     * @param Document $collection
     *
     * @return bool
     */
    public function deleteCollection(Document $collection): bool
    {
        $query = $this->getPDO()->prepare('DROP TABLE `app_'.$this->getNamespace().'.collection.'.$collection->getId().'`;');

        if (!$query->execute()) {
            return false;
        }

        $rules = $collection->getAttribute('rules', []);
        
        foreach ($rules as $attribute) { /** @var Document $attribute */
            $key = $attribute->getAttribute('key');
            $array = $attribute->getAttribute('array');

            if($array) {
                $this->deleteAttribute($collection, $key, $array);
            }
        }

        return true;
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
        if($array) {
            $query = $this->getPDO()->prepare('CREATE TABLE `app_'.$this->getNamespace().'.collection.'.$collection->getId().'.'.$id.'` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `uid` varchar(45) DEFAULT NULL,
                '.$this->getAttributeType($id, $type).',
                PRIMARY KEY (`id`),
                KEY `index1` (`uid`)
              ) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4;
            ;');
        }
        else {
            $query = $this->getPDO()->prepare('ALTER TABLE `app_'.$this->getNamespace().'.collection.'.$collection->getId().'`
                ADD COLUMN '.$this->getAttributeType($id, $type).';');
        }
        
        if (!$query->execute()) {
            return false;
        }

        return true;
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
        if($array) {
            $query = $this->getPDO()->prepare('DROP TABLE `app_'.$this->getNamespace().'.collection.'.$collection->getId().'.'.$id.'`;');
        }
        else {
            $query = $this->getPDO()->prepare('ALTER TABLE `app_'.$this->getNamespace().'.collection.'.$collection->getId().'`
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
     * @param Document $collection
     * @param string $id
     * @param string $type
     * @param array $attributes
     *
     * @return bool
     */
    public function createIndex(Document $collection, string $id, string $type, array $attributes): bool
    {
        $query = $this->getPDO()->prepare('ALTER TABLE `app_'.$this->getNamespace().'.collection.'.$collection->getId().'`
            ADD '.$this->getIndexType($id, $type, $attributes) . ';');

        if (!$query->execute()) {
            return false;
        }

        return true;
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
        $query = $this->getPDO()->prepare('ALTER TABLE `app_'.$this->getNamespace().'.collection.'.$collection->getId().'`
            DROP INDEX `index_'.$id.'`;');

        if (!$query->execute()) {
            return false;
        }

        return true;
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
        // Get fields abstraction
        $st = $this->getPDO()->prepare('SELECT * FROM `app_'.$this->getNamespace().'.collection.'.$collection->getId().'` documents
            WHERE documents.uid = :uid;
        ');

        $st->bindValue(':uid', $id, PDO::PARAM_STR);

        $st->execute();

        $document = $st->fetch();

        if (empty($document)) { // Not Found
            return [];
        }

        $rules = $collection->getAttribute('rules', []);
        $data = [];

        $data['$id'] = (isset($document['uid'])) ? $document['uid'] : null;
        $data['$collection'] = $collection->getId();
        $data['$permissions'] = (isset($document['permissions'])) ? json_decode($document['permissions'], true) : new stdClass;

        foreach($rules as $i => $rule) { /** @var Document $rule */
            $key = $rule->getAttribute('key');
            $type = $rule->getAttribute('type');
            $array = $rule->getAttribute('array');
            $list = $rule->getAttribute('list', []);
            $value = (isset($document['col_'.$key])) ? $document['col_'.$key] : null;

            if(array_key_exists($key, $this->protected)) {
                continue;
            }

            if($array) {                
                $st = $this->getPDO()->prepare('SELECT * FROM `app_'.$this->getNamespace().'.collection.'.$collection->getId().'.'.$key.'` documents
                    WHERE documents.uid = :uid;
                ');

                $st->bindValue(':uid', $id, PDO::PARAM_STR);

                $st->execute();

                $elements = $st->fetchAll();

                $value = [];

                foreach ($elements as $element) {
                    $value[] = (isset($element['col_'.$key])) ? $element['col_'.$key] : null;
                }
            }

            $value = ($array) ? $value : [$value];

            if($array && !\is_array($value)) {
                continue;
            }
            
            foreach($value as $i => $element) {
                switch($type) {
                    case Database::VAR_INTEGER:
                        $value[$i] = (int)$element;
                        break;
                    case Database::VAR_FLOAT:
                    case Database::VAR_NUMERIC:
                        $value[$i] = (float)$element;
                        break;
                    case Database::VAR_BOOLEAN:
                        $value[$i] = ($element === '1');
                        break;
                    case Database::VAR_DOCUMENT:
                        $value[$i] = $this->getDatabase()->getDocument(array_pop(array_reverse($list)), $element);
                        break;
                }
            }

            $data[$key] = ($array) ? $value : $value[0];
        }

        return $data;
    }

    /**
     * Create Document.
     *
     * @param Document $collection
     * @param array $data
     * @param array $unique
     *
     * @throws \Exception
     *
     * @return array
     */
    public function createDocument(Document $collection, array $data, array $unique = [])
    {
        $data['$id'] = $this->getId();
        $data['$permissions'] = (!isset($data['$permissions'])) ? [] : $data['$permissions'];
        $columns = [];
        $rules = $collection->getAttribute('rules', []);

        foreach($rules as $i => $rule) {
            $key = $rule->getAttribute('key');
            $type = $rule->getAttribute('type');
            $array = $rule->getAttribute('array');

            if(array_key_exists($key, $this->protected) || $array) {
                continue;
            }

            $columns[] = '`col_'.$key.'` = :col_'.$i;
        }

        $columns = (!empty($columns)) ? ', '.implode(', ', $columns) : '';

        /**
         * Check Unique Keys
         */
        //throw new Duplicate('Duplicated Property');

        $this->beginTransaction();
        
        $st = $this->getPDO()->prepare('INSERT INTO `app_'.$this->getNamespace().'.collection.'.$collection->getId().'`
            SET uid = :uid, createdAt = :createdAt, updatedAt = :updatedAt, permissions = :permissions'.$columns.';
        ');

        $st->bindValue(':uid', $data['$id'], PDO::PARAM_STR);
        $st->bindValue(':createdAt', \date('Y-m-d H:i:s', \time()), PDO::PARAM_STR);
        $st->bindValue(':updatedAt', \date('Y-m-d H:i:s', \time()), PDO::PARAM_STR);
        $st->bindValue(':permissions', \json_encode($data['$permissions']), PDO::PARAM_STR);

        foreach($rules as $i => $rule) { /** @var Document $rule */
            $key = $rule->getAttribute('key');
            $type = $rule->getAttribute('type');
            $array = $rule->getAttribute('array');
            $list = $rule->getAttribute('list', []);
            $value = (isset($data[$key])) ? $data[$key] : null;

            if(array_key_exists($key, $this->protected)) {
                continue;
            }

            $value = ($array) ? $value : [$value];
            $value = ($array && !\is_array($value)) ? [] : $value;

            foreach ($value as $x => $element) {
                switch($type) {
                    case Database::VAR_DOCUMENT:
                        $id = (isset($element['$id'])) ? $element['$id'] : null;

                        $value[$x] = (empty($id))
                            ? $this->getDatabase()->createDocument(array_pop(array_reverse($list)), $element)->getId()
                            : $this->getDatabase()->updateDocument(array_pop(array_reverse($list)), $id, $element)->getId();
                    break;
                }
            }
            
            $value = ($array) ? $value : $value[0];

            if($array) {
                if(!is_array($value)) {
                    continue;
                }
                
                foreach ($value as $element) {
                    $stArray = $this->getPDO()->prepare('INSERT INTO `app_'.$this->getNamespace().'.collection.'.$collection->getId().'.'.$key.'`
                        SET uid = :uid, `col_'.$key.'` = :col_x;
                    ');
            
                    $stArray->bindValue(':uid', $data['$id'], PDO::PARAM_STR);
                    $stArray->bindValue(':col_x', $element, $this->getDataType($type));
                    $stArray->execute();
                }

                continue;
            }
            
            if(!$array) {
                $st->bindValue(':col_'.$i, $value, $this->getDataType($type));
            }
        }

        try {
            $st->execute();
        } catch (\Throwable $th) {
            switch ($th->getCode()) {
                case '23000':
                    throw new Duplicate('Duplicated documents');
                    break;
            }

            throw $th;
        }

        $this->commit();

        //TODO remove this dependency (check if related to nested documents)
        // $this->getRedis()->expire($this->getNamespace().':document-'.$data['$id'], 0);
        // $this->getRedis()->expire($this->getNamespace().':document-'.$data['$id'], 0);

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
        if(!isset($data['$id']) || empty($data['$id']) || empty($id)) {
            throw new Exception('$id is missing');
        }

        $data['$permissions'] = (!isset($data['$permissions'])) ? [] : $data['$permissions'];
        $columns = [];
        $rules = $collection->getAttribute('rules', []);

        foreach($rules as $i => $rule) {
            $key = $rule->getAttribute('key');
            $type = $rule->getAttribute('type');
            $array = $rule->getAttribute('array');

            if(array_key_exists($key, $this->protected) || $array) {
                continue;
            }

            $columns[] = '`col_'.$key.'` = :col_'.$i;
        }

        $columns = (!empty($columns)) ? ', '.implode(', ', $columns) : '';

        /**
         * Check Unique Keys
         */
        //throw new Duplicate('Duplicated Property');

        $this->beginTransaction();
        
        $st = $this->getPDO()->prepare('UPDATE `app_'.$this->getNamespace().'.collection.'.$collection->getId().'`
            SET updatedAt = :updatedAt, permissions = :permissions'.$columns.'
            WHERE uid = :uid;
        ');

        $st->bindValue(':uid', $data['$id'], PDO::PARAM_STR);
        $st->bindValue(':updatedAt', \date('Y-m-d H:i:s', \time()), PDO::PARAM_STR);
        $st->bindValue(':permissions', \json_encode($data['$permissions']), PDO::PARAM_STR);

        foreach($rules as $i => $rule) { /** @var Document $rule */
            $key = $rule->getAttribute('key');
            $type = $rule->getAttribute('type');
            $array = $rule->getAttribute('array');
            $list = $rule->getAttribute('list', []);
            $value = (isset($data[$key])) ? $data[$key] : null;

            if(array_key_exists($key, $this->protected)) {
                continue;
            }

            $value = ($array) ? $value : [$value];
            $value = ($array && !\is_array($value)) ? [] : $value;

            foreach ($value as $x => $element) {
                switch($type) {
                    case Database::VAR_DOCUMENT:
                        $id = (isset($element['$id'])) ? $element['$id'] : null;
                        $value[$x] = (empty($id))
                            ? $this->getDatabase()->createDocument(array_pop(array_reverse($list)), $element)->getId()
                            : $this->getDatabase()->updateDocument(array_pop(array_reverse($list)), $id, $element)->getId();
                    break;
                }
            }
            
            $value = ($array) ? $value : $value[0];

            if($array) {
                if(!is_array($value)) {
                    continue;
                }

                $stArray = $this->getPDO()->prepare('DELETE FROM `app_'.$this->getNamespace().'.collection.'.$collection->getId().'.'.$key.'`
                    WHERE uid = :uid;
                ');

                $stArray->bindValue(':uid', $data['$id'], PDO::PARAM_STR);
                $stArray->execute();
                
                foreach ($value as $element) {
                    $stArray = $this->getPDO()->prepare('INSERT INTO `app_'.$this->getNamespace().'.collection.'.$collection->getId().'.'.$key.'`
                        SET uid = :uid, `col_'.$key.'` = :col_x;
                    ');
            
                    $stArray->bindValue(':uid', $data['$id'], PDO::PARAM_STR);
                    $stArray->bindValue(':col_x', $element, $this->getDataType($type));
                    $stArray->execute();
                }

                continue;
            }
            
            if(!$array) {
                $st->bindValue(':col_'.$i, $value, $this->getDataType($type));
            }
        }

        try {
            $st->execute();
        } catch (\Throwable $th) {
            switch ($th->getCode()) {
                case '23000':
                    throw new Duplicate('Duplicated documents');
                    break;
            }

            throw $th;
        }

        $this->commit();

        //TODO remove this dependency (check if related to nested documents)
        // $this->getRedis()->expire($this->getNamespace().':document-'.$data['$id'], 0);
        // $this->getRedis()->expire($this->getNamespace().':document-'.$data['$id'], 0);

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
        $rules = $collection->getAttribute('rules', []);

        $this->beginTransaction();
        
        $st = $this->getPDO()->prepare('DELETE FROM `app_'.$this->getNamespace().'.collection.'.$collection->getId().'`
            WHERE uid = :uid
		');

        $st->bindValue(':uid', $id, PDO::PARAM_STR);

        $st->execute();

        foreach($rules as $i => $rule) { /** @var Document $rule */
            $key = $rule->getAttribute('key');
            $array = $rule->getAttribute('array');

            if($array) {
                $stArray = $this->getPDO()->prepare('DELETE FROM `app_'.$this->getNamespace().'.collection.'.$collection->getId().'.'.$key.'`
                    WHERE uid = :uid;
                ');

                $stArray->bindValue(':uid', $id, PDO::PARAM_STR);
                $stArray->execute();
            }
        }

        $st->execute();

        $this->commit();

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

        $audit = 'app_'.$namespace.'.audit.audit';
        $abuse = 'app_'.$namespace.'.abuse.abuse';

        /**
         * 1. Itterate default collections
         * 2. Create collection
         * 3. Create all regular and array fields
         * 4. Create all indexes
         * 5. Create audit / abuse tables
         */

        foreach($this->getMocks() as $collection) { /** @var Document $collection */
            $this->createCollection($collection, $collection->getId());
        }

        try {
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

        $audit = 'app_'.$namespace.'.audit.audit';
        $abuse = 'app_'.$namespace.'.abuse.abuse';

        foreach($this->getMocks() as $collection) { /** @var Document $collection */
            $this->deleteCollection($collection, $collection->getId());
        }

        // TODO Delete all custom collections

        try {
            $this->getPDO()->prepare('DROP TABLE `'.$audit.'`;')->execute();
            $this->getPDO()->prepare('DROP TABLE `'.$abuse.'`;')->execute();
        } catch (Exception $e) {
            throw $e;
        }

        return true;
    }

    /**
     * Find
     *
     * @param Document $collection
     * @param array $options
     *
     * @throws Exception
     *
     * @return array
     */
    public function find(Document $collection, array $options)
    {
        $start = \microtime(true);

        $orderTypeMap = ['DESC', 'ASC'];

        $options['orderField'] = (empty($options['orderField'])) ? '$id' : $options['orderField']; // Set default order field

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
            }
            else {
                $path = [];
            }

            //$key = $this->getPDO()->quote($key, PDO::PARAM_STR);
            $value = $this->getPDO()->quote($value, PDO::PARAM_STR);
            
            $options['offset'] = (int) $options['offset'];
            $options['limit'] = (int) $options['limit'];

            if (empty($path)) {
                $where[] = "(col_{$key} {$operator} {$value})";
            }
            else { // Handle direct child attributes queries
                // $len = \count($original);
                // $prev = 'c'.$i;

                // foreach ($original as $y => $part) {
                //     $part = $this->getPDO()->quote($part, PDO::PARAM_STR);

                //     if (0 === $y) { // First key
                //         $join[$i] = 'JOIN `'.$this->getNamespace().".database.relationships` c{$i} ON a.uid IS NOT NULL AND c{$i}.start = a.uid AND c{$i}.key = {$part}";
                //     } elseif ($y == $len - 1) { // Last key
                //         $join[$i] .= 'JOIN `'.$this->getNamespace().".database.properties` e{$i} ON e{$i}.documentUid = {$prev}.end AND e{$i}.key = {$part} AND e{$i}.value {$operator} {$value}";
                //     } else {
                //         $join[$i] .= 'JOIN `'.$this->getNamespace().".database.relationships` d{$i}{$y} ON d{$i}{$y}.start = {$prev}.end AND d{$i}{$y}.key = {$part}";
                //         $prev = 'd'.$i.$y;
                //     }
                // }
            }
        }

        $orderSelect = '';
        // // Sorting
        // $orderPath = \explode('.', $options['orderField']);
        // $len = \count($orderPath);
        // $orderKey = 'order_b';
        // $part = $this->getPDO()->quote(\implode('', $orderPath), PDO::PARAM_STR);
        // $orderSelect = "CASE WHEN {$orderKey}.key = {$part} THEN CAST({$orderKey}.value AS {$orderCastMap[$options['orderCast']]}) END AS sort_ff";

        // if (1 === $len) {
        //     //if($path == "''") { // Handle direct attributes queries
        //     $sorts[] = 'LEFT JOIN `'.$this->getNamespace().".database.properties` order_b ON a.uid IS NOT NULL AND order_b.documentUid = a.uid AND (order_b.key = {$part})";
        // } else { // Handle direct child attributes queries
        //     $prev = 'c';
        //     $orderKey = 'order_e';

        //     foreach ($orderPath as $y => $part) {
        //         $part = $this->getPDO()->quote($part, PDO::PARAM_STR);
        //         $x = $y - 1;

        //         if (0 === $y) { // First key
        //             $sorts[] = 'JOIN `'.$this->getNamespace().".database.relationships` order_c{$y} ON a.uid IS NOT NULL AND order_c{$y}.start = a.uid AND order_c{$y}.key = {$part}";
        //         } elseif ($y == $len - 1) { // Last key
        //             $sorts[] .= 'JOIN `'.$this->getNamespace().".database.properties` order_e ON order_e.documentUid = order_{$prev}{$x}.end AND order_e.key = {$part}";
        //         } else {
        //             $sorts[] .= 'JOIN `'.$this->getNamespace().".database.relationships` order_d{$y} ON order_d{$y}.start = order_{$prev}{$x}.end AND order_d{$y}.key = {$part}";
        //             $prev = 'd';
        //         }
        //     }
        // }

        /*
         * Workaround for a MySQL bug as reported here:
         * https://bugs.mysql.com/bug.php?id=78485
         */
        // $options['search'] = ($options['search'] === '*') ? '' : $options['search'];

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
        $where = \implode(" AND \n", $where);
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

        $query = 'SELECT %s
            FROM `app_'.$this->getNamespace().'.collection.'.$collection->getId().'` a
                '.$join.'
                '.$sorts.'
            WHERE
                '.$where.'
                '.$search.'
                AND ('.\implode('||', $roles).')
            ORDER BY sort_ff '.$options['orderType'].' %s';

        var_dump(\preg_replace('/\s+/', ' ', \sprintf($query, $select, $range)));

        return [];
        $st = $this->getPDO()->prepare(\sprintf($query, $select, $range));

        $st->execute();

        $results = ['data' => []];

        // Get entire fields data for each id
        foreach ($st->fetchAll() as $node) {
            $results['data'][] = $node['uid'];
        }

        // $count = $this->getPDO()->prepare(\sprintf($query, 'count(DISTINCT a.uid) as sum', ''));
        // $count->execute();
        // $count = $count->fetch();

        $this->resetDebug();

        $this
            ->setDebug('query', \preg_replace('/\s+/', ' ', \sprintf($query, $select, $range)))
            ->setDebug('time', \microtime(true) - $start)
            ->setDebug('filters', \count($options['filters']))
            ->setDebug('joins', \substr_count($query, 'JOIN'))
            ->setDebug('count', \count($results['data']))
            ->setDebug('sum', (int) 0)
        ;

        return $results['data'];
    }

    /**
     * Count
     *
     * @param array $options
     *
     * @throws Exception
     *
     * @return int
     */
    public function count(array $options)
    {
        return 0;
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
     * Get PDO Data Type.
     *
     * @param $type
     *
     * @return string
     *
     * @throws \Exception
     */
    protected function getDataType($type)
    {
        switch ($type) {
            case Database::VAR_TEXT:
            case Database::VAR_URL:
            case Database::VAR_KEY:
            case Database::VAR_IPV4:
            case Database::VAR_IPV6:
            case Database::VAR_EMAIL:
            case Database::VAR_FLOAT:
            case Database::VAR_NUMERIC:
                return PDO::PARAM_STR;
                break;

            case Database::VAR_DOCUMENT:
                return PDO::PARAM_STR;
                break;

            case Database::VAR_INTEGER:
                return PDO::PARAM_INT;
                break;
            
            case Database::VAR_BOOLEAN:
                return PDO::PARAM_BOOL;
                break;

            default:
                throw new Exception('Unsupported attribute: '.$type);
                break;
        }
    }

    /**
     * Get Column
     * 
     * @var string $key
     * @var string $type
     * 
     * @return string
     */
    protected function getAttributeType(string $key, string $type): string
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
                //return '`col_'.$key.'` INT UNSIGNED NULL';
                return '`col_'.$key.'` VARCHAR(15) NULL';
                break;

            case Database::VAR_IPV6:
                //return '`col_'.$key.'` BINARY(16) NULL';
                return '`col_'.$key.'` VARCHAR(39) NULL';
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
                throw new Exception('Unsupported attribute: '.$type);
                break;
        }
    }

    /**
     * @var string $type
     * 
     * @throws Exceptions
     * 
     * @return string
     */
    protected function getIndexType(string $key, string $type, array $attributes): string
    {
        $index = '';
        $columns = [];

        foreach ($attributes as $attribute) {
            $columns[] = '`col_'.$attribute.'`(32) ASC'; // TODO custom size limit per type
        }

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

        return $index .' `index_'.$key.'` ('.implode(',', $columns).')';
    }

    /**
     * @return false
     */
    protected function beginTransaction(): bool
    {
        if($this->transaction) {
            return false;
        }

        $this->transaction = true;

        $this->getPDO()->beginTransaction();

        return true;
    }

    /**
     * @return false
     */
    protected function commit(): bool
    {
        if(!$this->transaction) {
            return false;
        }

        $this->getPDO()->commit();

        $this->transaction = false;

        return true;
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

    public function deleteUniqueKey($key)
    {
        return [];
    }
}
