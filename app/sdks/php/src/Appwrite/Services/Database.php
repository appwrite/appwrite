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
     * Get a list of all the user collections. You can use the query params to filter your results. On admin mode, this endpoint will return a list of all of the project collections. [Learn more about different API modes](/docs/modes).
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
     * Create a new Collection.
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
     * List Documents
     *
     * Get a list of all the user documents. You can use the query params to filter your results. On admin mode, this endpoint will return a list of all of the project documents. [Learn more about different API modes](/docs/modes).
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
        $path   = str_replace(['{collectionId}'], [$collectionId], '/database/{collectionId}');
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
     * Create a new Document.
     *
     * @param string $collectionId
     * @param string $data
     * @param string $parentDocument
     * @param string $parentProperty
     * @param string $parentPropertyType
     * @throws Exception
     * @return array
     */
    public function createDocument($collectionId, $data, $parentDocument = '', $parentProperty = '', $parentPropertyType = 'assign')
    {
        $path   = str_replace(['{collectionId}'], [$collectionId], '/database/{collectionId}');
        $params = [];

        $params['data'] = $data;
        $params['parentDocument'] = $parentDocument;
        $params['parentProperty'] = $parentProperty;
        $params['parentPropertyType'] = $parentPropertyType;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * Delete Collection
     *
     * Delete a collection by its unique ID. Only users with write permissions have access to delete this resource.
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
     * Get Document
     *
     * Get document by its unique ID. This endpoint response returns a JSON object with the document data.
     *
     * @param string $collectionId
     * @param string $documentId
     * @throws Exception
     * @return array
     */
    public function getDocument($collectionId, $documentId)
    {
        $path   = str_replace(['{collectionId}', '{documentId}'], [$collectionId, $documentId], '/database/{collectionId}/{documentId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Update Document
     *
     * @param string $collectionId
     * @param string $documentId
     * @param string $data
     * @throws Exception
     * @return array
     */
    public function updateDocument($collectionId, $documentId, $data)
    {
        $path   = str_replace(['{collectionId}', '{documentId}'], [$collectionId, $documentId], '/database/{collectionId}/{documentId}');
        $params = [];

        $params['data'] = $data;

        return $this->client->call(Client::METHOD_PATCH, $path, [
        ], $params);
    }

    /**
     * Delete Document
     *
     * Delete document by its unique ID. This endpoint deletes only the parent documents, his attributes and relations to other documents. Child documents **will not** be deleted.
     *
     * @param string $collectionId
     * @param string $documentId
     * @throws Exception
     * @return array
     */
    public function deleteDocument($collectionId, $documentId)
    {
        $path   = str_replace(['{collectionId}', '{documentId}'], [$collectionId, $documentId], '/database/{collectionId}/{documentId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
        ], $params);
    }

}