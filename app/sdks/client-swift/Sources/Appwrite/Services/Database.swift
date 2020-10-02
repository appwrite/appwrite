class Database: Service
{
    /**
     * List Documents
     *
     * Get a list of all the user documents. You can use the query params to
     * filter your results. On admin mode, this endpoint will return a list of all
     * of the project documents. [Learn more about different API
     * modes](/docs/admin).
     *
     * @param String _collectionId
     * @param Array<Any> _filters
     * @param Int _limit
     * @param Int _offset
     * @param String _orderField
     * @param String _orderType
     * @param String _orderCast
     * @param String _search
     * @throws Exception
     * @return array
     */

    func listDocuments(_collectionId: String, _filters: Array<Any> = [], _limit: Int = 25, _offset: Int = 0, _orderField: String = "$id", _orderType: String = "ASC", _orderCast: String = "string", _search: String = "") -> Array<Any> {
        var path: String = "/database/collections/{collectionId}/documents"

        path = path.replacingOccurrences(
          of: "{collectionId}",
          with: _collectionId
        )

                var params: [String: Any] = [:]
        
        params["filters"] = _filters
        params["limit"] = _limit
        params["offset"] = _offset
        params["orderField"] = _orderField
        params["orderType"] = _orderType
        params["orderCast"] = _orderCast
        params["search"] = _search

        return [self.client.call(method: Client.HTTPMethod.get.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Create Document
     *
     * Create a new Document. Before using this route, you should create a new
     * collection resource using either a [server
     * integration](/docs/server/database?sdk=nodejs#createCollection) API or
     * directly from your database console.
     *
     * @param String _collectionId
     * @param object _data
     * @param Array<Any> _read
     * @param Array<Any> _write
     * @throws Exception
     * @return array
     */

    func createDocument(_collectionId: String, _data: object, _read: Array<Any>, _write: Array<Any>) -> Array<Any> {
        var path: String = "/database/collections/{collectionId}/documents"

        path = path.replacingOccurrences(
          of: "{collectionId}",
          with: _collectionId
        )

                var params: [String: Any] = [:]
        
        params["data"] = _data
        params["read"] = _read
        params["write"] = _write

        return [self.client.call(method: Client.HTTPMethod.post.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Get Document
     *
     * Get document by its unique ID. This endpoint response returns a JSON object
     * with the document data.
     *
     * @param String _collectionId
     * @param String _documentId
     * @throws Exception
     * @return array
     */

    func getDocument(_collectionId: String, _documentId: String) -> Array<Any> {
        var path: String = "/database/collections/{collectionId}/documents/{documentId}"

        path = path.replacingOccurrences(
          of: "{collectionId}",
          with: _collectionId
        )
        path = path.replacingOccurrences(
          of: "{documentId}",
          with: _documentId
        )

                let params: [String: Any] = [:]
        

        return [self.client.call(method: Client.HTTPMethod.get.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Update Document
     *
     * @param String _collectionId
     * @param String _documentId
     * @param object _data
     * @param Array<Any> _read
     * @param Array<Any> _write
     * @throws Exception
     * @return array
     */

    func updateDocument(_collectionId: String, _documentId: String, _data: object, _read: Array<Any>, _write: Array<Any>) -> Array<Any> {
        var path: String = "/database/collections/{collectionId}/documents/{documentId}"

        path = path.replacingOccurrences(
          of: "{collectionId}",
          with: _collectionId
        )
        path = path.replacingOccurrences(
          of: "{documentId}",
          with: _documentId
        )

                var params: [String: Any] = [:]
        
        params["data"] = _data
        params["read"] = _read
        params["write"] = _write

        return [self.client.call(method: Client.HTTPMethod.patch.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Delete Document
     *
     * Delete document by its unique ID. This endpoint deletes only the parent
     * documents, his attributes and relations to other documents. Child documents
     * **will not** be deleted.
     *
     * @param String _collectionId
     * @param String _documentId
     * @throws Exception
     * @return array
     */

    func deleteDocument(_collectionId: String, _documentId: String) -> Array<Any> {
        var path: String = "/database/collections/{collectionId}/documents/{documentId}"

        path = path.replacingOccurrences(
          of: "{collectionId}",
          with: _collectionId
        )
        path = path.replacingOccurrences(
          of: "{documentId}",
          with: _documentId
        )

                let params: [String: Any] = [:]
        

        return [self.client.call(method: Client.HTTPMethod.delete.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

}
