<?php

namespace Appwrite\Services;

use Exception;
use Appwrite\Client;
use Appwrite\Service;

class Database extends Service
{
    /**
     * List Collections
     *
     * /docs/references/database/list-collections.md
     *
     * @param string $search
     * @param integer $limit
     * @param integer $offset
     * @param string $orderType
     * @throws Exception
     * @return array
     */
    public function listCollections($search = '', $limit = 25, $offset = 0, $orderType = 'ASC')
    {
        $path   = str_replace([], [], '/database');
        $params = [];

        $params['search'] = $search;
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        $params['orderType'] = $orderType;

        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Create Collection
     *
     * /docs/references/database/create-collection.md
     *
     * @param string $name
     * @param array $read
     * @param array $write
     * @param array $rules
     * @throws Exception
     * @return array
     */
    public function createCollection($name, $read = [], $write = [], $rules = [])
    {
        $path   = str_replace([], [], '/database');
        $params = [];

        $params['name'] = $name;
        $params['read'] = $read;
        $params['write'] = $write;
        $params['rules'] = $rules;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * Get Collection
     *
     * /docs/references/database/get-collection.md
     *
     * @param string $collectionId
     * @throws Exception
     * @return array
     */
    public function getCollection($collectionId)
    {
        $path   = str_replace(['{collectionId}'], [$collectionId], '/database/{collectionId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Update Collection
     *
     * /docs/references/database/update-collection.md
     *
     * @param string $collectionId
     * @param string $name
     * @param array $read
     * @param array $write
     * @param array $rules
     * @throws Exception
     * @return array
     */
    public function updateCollection($collectionId, $name, $read = [], $write = [], $rules = [])
    {
        $path   = str_replace(['{collectionId}'], [$collectionId], '/database/{collectionId}');
        $params = [];

        $params['name'] = $name;
        $params['read'] = $read;
        $params['write'] = $write;
        $params['rules'] = $rules;

        return $this->client->call(Client::METHOD_PUT, $path, [
        ], $params);
    }

    /**
     * Delete Collection
     *
     * /docs/references/database/delete-collection.md
     *
     * @param string $collectionId
     * @throws Exception
     * @return array
     */
    public function deleteCollection($collectionId)
    {
        $path   = str_replace(['{collectionId}'], [$collectionId], '/database/{collectionId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
        ], $params);
    }

    /**
     * List Documents
     *
     * /docs/references/database/list-documents.md
     *
     * @param string $collectionId
     * @param array $filters
     * @param integer $offset
     * @param integer $limit
     * @param string $orderField
     * @param string $orderType
     * @param string $orderCast
     * @param string $search
     * @param integer $first
     * @param integer $last
     * @throws Exception
     * @return array
     */
    public function listDocuments($collectionId, $filters = [], $offset = 0, $limit = 50, $orderField = '$uid', $orderType = 'ASC', $orderCast = 'string', $search = '', $first = 0, $last = 0)
    {
        $path   = str_replace(['{collectionId}'], [$collectionId], '/database/{collectionId}/documents');
        $params = [];

        $params['filters'] = $filters;
        $params['offset'] = $offset;
        $params['limit'] = $limit;
        $params['order-field'] = $orderField;
        $params['order-type'] = $orderType;
        $params['order-cast'] = $orderCast;
        $params['search'] = $search;
        $params['first'] = $first;
        $params['last'] = $last;

        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Create Document
     *
     * /docs/references/database/create-document.md
     *
     * @param string $collectionId
     * @param string $data
     * @param array $read
     * @param array $write
     * @param string $parentDocument
     * @param string $parentProperty
     * @param string $parentPropertyType
     * @throws Exception
     * @return array
     */
    public function createDocument($collectionId, $data, $read = [], $write = [], $parentDocument = '', $parentProperty = '', $parentPropertyType = 'assign')
    {
        $path   = str_replace(['{collectionId}'], [$collectionId], '/database/{collectionId}/documents');
        $params = [];

        $params['data'] = $data;
        $params['read'] = $read;
        $params['write'] = $write;
        $params['parentDocument'] = $parentDocument;
        $params['parentProperty'] = $parentProperty;
        $params['parentPropertyType'] = $parentPropertyType;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * Get Document
     *
     * /docs/references/database/get-document.md
     *
     * @param string $collectionId
     * @param string $documentId
     * @throws Exception
     * @return array
     */
    public function getDocument($collectionId, $documentId)
    {
        $path   = str_replace(['{collectionId}', '{documentId}'], [$collectionId, $documentId], '/database/{collectionId}/documents/{documentId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Update Document
     *
     * /docs/references/database/update-document.md
     *
     * @param string $collectionId
     * @param string $documentId
     * @param string $data
     * @param array $read
     * @param array $write
     * @throws Exception
     * @return array
     */
    public function updateDocument($collectionId, $documentId, $data, $read = [], $write = [])
    {
        $path   = str_replace(['{collectionId}', '{documentId}'], [$collectionId, $documentId], '/database/{collectionId}/documents/{documentId}');
        $params = [];

        $params['data'] = $data;
        $params['read'] = $read;
        $params['write'] = $write;

        return $this->client->call(Client::METHOD_PATCH, $path, [
        ], $params);
    }

    /**
     * Delete Document
     *
     * /docs/references/database/delete-document.md
     *
     * @param string $collectionId
     * @param string $documentId
     * @throws Exception
     * @return array
     */
    public function deleteDocument($collectionId, $documentId)
    {
        $path   = str_replace(['{collectionId}', '{documentId}'], [$collectionId, $documentId], '/database/{collectionId}/documents/{documentId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
        ], $params);
    }

}