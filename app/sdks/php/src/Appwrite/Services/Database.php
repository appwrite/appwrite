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
     * Get a list of all the user collections. You can use the query params to
     * filter your results. On admin mode, this endpoint will return a list of all
     * of the project collections. [Learn more about different API
     * modes](/docs/admin).
     *
     * @param string  $search
     * @param int  $limit
     * @param int  $offset
     * @param string  $orderType
     * @throws Exception
     * @return array
     */
    public function listCollections(string $search = '', int $limit = 25, int $offset = 0, string $orderType = 'ASC'):array
    {
        $path   = str_replace([], [], '/database/collections');
        $params = [];

        $params['search'] = $search;
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        $params['orderType'] = $orderType;

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Create Collection
     *
     * Create a new Collection.
     *
     * @param string  $name
     * @param array  $read
     * @param array  $write
     * @param array  $rules
     * @throws Exception
     * @return array
     */
    public function createCollection(string $name, array $read, array $write, array $rules):array
    {
        $path   = str_replace([], [], '/database/collections');
        $params = [];

        $params['name'] = $name;
        $params['read'] = $read;
        $params['write'] = $write;
        $params['rules'] = $rules;

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get Collection
     *
     * Get collection by its unique ID. This endpoint response returns a JSON
     * object with the collection metadata.
     *
     * @param string  $collectionId
     * @throws Exception
     * @return array
     */
    public function getCollection(string $collectionId):array
    {
        $path   = str_replace(['{collectionId}'], [$collectionId], '/database/collections/{collectionId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Update Collection
     *
     * Update collection by its unique ID.
     *
     * @param string  $collectionId
     * @param string  $name
     * @param array  $read
     * @param array  $write
     * @param array  $rules
     * @throws Exception
     * @return array
     */
    public function updateCollection(string $collectionId, string $name, array $read, array $write, array $rules = []):array
    {
        $path   = str_replace(['{collectionId}'], [$collectionId], '/database/collections/{collectionId}');
        $params = [];

        $params['name'] = $name;
        $params['read'] = $read;
        $params['write'] = $write;
        $params['rules'] = $rules;

        return $this->client->call(Client::METHOD_PUT, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Delete Collection
     *
     * Delete a collection by its unique ID. Only users with write permissions
     * have access to delete this resource.
     *
     * @param string  $collectionId
     * @throws Exception
     * @return array
     */
    public function deleteCollection(string $collectionId):array
    {
        $path   = str_replace(['{collectionId}'], [$collectionId], '/database/collections/{collectionId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * List Documents
     *
     * Get a list of all the user documents. You can use the query params to
     * filter your results. On admin mode, this endpoint will return a list of all
     * of the project documents. [Learn more about different API
     * modes](/docs/admin).
     *
     * @param string  $collectionId
     * @param array  $filters
     * @param int  $offset
     * @param int  $limit
     * @param string  $orderField
     * @param string  $orderType
     * @param string  $orderCast
     * @param string  $search
     * @param int  $first
     * @param int  $last
     * @throws Exception
     * @return array
     */
    public function listDocuments(string $collectionId, array $filters = [], int $offset = 0, int $limit = 50, string $orderField = '$uid', string $orderType = 'ASC', string $orderCast = 'string', string $search = '', int $first = 0, int $last = 0):array
    {
        $path   = str_replace(['{collectionId}'], [$collectionId], '/database/collections/{collectionId}/documents');
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
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Create Document
     *
     * Create a new Document.
     *
     * @param string  $collectionId
     * @param array  $data
     * @param array  $read
     * @param array  $write
     * @param string  $parentDocument
     * @param string  $parentProperty
     * @param string  $parentPropertyType
     * @throws Exception
     * @return array
     */
    public function createDocument(string $collectionId, array $data, array $read, array $write, string $parentDocument = '', string $parentProperty = '', string $parentPropertyType = 'assign'):array
    {
        $path   = str_replace(['{collectionId}'], [$collectionId], '/database/collections/{collectionId}/documents');
        $params = [];

        $params['data'] = $data;
        $params['read'] = $read;
        $params['write'] = $write;
        $params['parentDocument'] = $parentDocument;
        $params['parentProperty'] = $parentProperty;
        $params['parentPropertyType'] = $parentPropertyType;

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get Document
     *
     * Get document by its unique ID. This endpoint response returns a JSON object
     * with the document data.
     *
     * @param string  $collectionId
     * @param string  $documentId
     * @throws Exception
     * @return array
     */
    public function getDocument(string $collectionId, string $documentId):array
    {
        $path   = str_replace(['{collectionId}', '{documentId}'], [$collectionId, $documentId], '/database/collections/{collectionId}/documents/{documentId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Update Document
     *
     * @param string  $collectionId
     * @param string  $documentId
     * @param array  $data
     * @param array  $read
     * @param array  $write
     * @throws Exception
     * @return array
     */
    public function updateDocument(string $collectionId, string $documentId, array $data, array $read, array $write):array
    {
        $path   = str_replace(['{collectionId}', '{documentId}'], [$collectionId, $documentId], '/database/collections/{collectionId}/documents/{documentId}');
        $params = [];

        $params['data'] = $data;
        $params['read'] = $read;
        $params['write'] = $write;

        return $this->client->call(Client::METHOD_PATCH, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Delete Document
     *
     * Delete document by its unique ID. This endpoint deletes only the parent
     * documents, his attributes and relations to other documents. Child documents
     * **will not** be deleted.
     *
     * @param string  $collectionId
     * @param string  $documentId
     * @throws Exception
     * @return array
     */
    public function deleteDocument(string $collectionId, string $documentId):array
    {
        $path   = str_replace(['{collectionId}', '{documentId}'], [$collectionId, $documentId], '/database/collections/{collectionId}/documents/{documentId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

}