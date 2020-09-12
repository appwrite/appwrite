

class Storage: Service
{
    /**
     * List Files
     *
     * Get a list of all the user files. You can use the query params to filter
     * your results. On admin mode, this endpoint will return a list of all of the
     * project files. [Learn more about different API modes](/docs/admin).
     *
     * @param String _search
     * @param Int _limit
     * @param Int _offset
     * @param String _orderType
     * @throws Exception
     * @return array
     */

    func listFiles(_search: String = "", _limit: Int = 25, _offset: Int = 0, _orderType: String = "ASC") -> Array<Any> {
        let path: String = "/storage/files"


                var params: [String: Any] = [:]
        
        params["search"] = _search
        params["limit"] = _limit
        params["offset"] = _offset
        params["orderType"] = _orderType

        return [self.client.call(method: Client.HTTPMethod.get.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)];
    }

    /**
     * Create File
     *
     * Create a new file. The user who creates the file will automatically be
     * assigned to read and write access unless he has passed custom values for
     * read and write arguments.
     *
     * @param Array<Any> _file
     * @param Array<Any> _read
     * @param Array<Any> _write
     * @throws Exception
     * @return array
     */

    func createFile(_file: Array<Any>, _read: Array<Any>, _write: Array<Any>) -> Array<Any> {
        let path: String = "/storage/files"


                var params: [String: Any] = [:]
        
        params["file"] = _file
        params["read"] = _read
        params["write"] = _write

        return [self.client.call(method: Client.HTTPMethod.post.rawValue, path: path, headers: [
            "content-type": "multipart/form-data",
        ], params: params)];
    }

    /**
     * Get File
     *
     * Get file by its unique ID. This endpoint response returns a JSON object
     * with the file metadata.
     *
     * @param String _fileId
     * @throws Exception
     * @return array
     */

    func getFile(_fileId: String) -> Array<Any> {
        var path: String = "/storage/files/{fileId}"

        path = path.replacingOccurrences(
          of: "{fileId}",
          with: _fileId
        )

                let params: [String: Any] = [:]
        

        return [self.client.call(method: Client.HTTPMethod.get.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)];
    }

    /**
     * Update File
     *
     * Update file by its unique ID. Only users with write permissions have access
     * to update this resource.
     *
     * @param String _fileId
     * @param Array<Any> _read
     * @param Array<Any> _write
     * @throws Exception
     * @return array
     */

    func updateFile(_fileId: String, _read: Array<Any>, _write: Array<Any>) -> Array<Any> {
        var path: String = "/storage/files/{fileId}"

        path = path.replacingOccurrences(
          of: "{fileId}",
          with: _fileId
        )

                var params: [String: Any] = [:]
        
        params["read"] = _read
        params["write"] = _write

        return [self.client.call(method: Client.HTTPMethod.put.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)];
    }

    /**
     * Delete File
     *
     * Delete a file by its unique ID. Only users with write permissions have
     * access to delete this resource.
     *
     * @param String _fileId
     * @throws Exception
     * @return array
     */

    func deleteFile(_fileId: String) -> Array<Any> {
        var path: String = "/storage/files/{fileId}"

        path = path.replacingOccurrences(
          of: "{fileId}",
          with: _fileId
        )

                let params: [String: Any] = [:]
        

        return [self.client.call(method: Client.HTTPMethod.delete.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)];
    }

    /**
     * Get File for Download
     *
     * Get file content by its unique ID. The endpoint response return with a
     * 'Content-Disposition: attachment' header that tells the browser to start
     * downloading the file to user downloads directory.
     *
     * @param String _fileId
     * @throws Exception
     * @return array
     */

    func getFileDownload(_fileId: String) -> Array<Any> {
        var path: String = "/storage/files/{fileId}/download"

        path = path.replacingOccurrences(
          of: "{fileId}",
          with: _fileId
        )

                let params: [String: Any] = [:]
        

        return [self.client.call(method: Client.HTTPMethod.get.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)];
    }

    /**
     * Get File Preview
     *
     * Get a file preview image. Currently, this method supports preview for image
     * files (jpg, png, and gif), other supported formats, like pdf, docs, slides,
     * and spreadsheets, will return the file icon image. You can also pass query
     * string arguments for cutting and resizing your preview image.
     *
     * @param String _fileId
     * @param Int _width
     * @param Int _height
     * @param Int _quality
     * @param String _background
     * @param String _output
     * @throws Exception
     * @return array
     */

    func getFilePreview(_fileId: String, _width: Int = 0, _height: Int = 0, _quality: Int = 100, _background: String = "", _output: String = "") -> Array<Any> {
        var path: String = "/storage/files/{fileId}/preview"

        path = path.replacingOccurrences(
          of: "{fileId}",
          with: _fileId
        )

                var params: [String: Any] = [:]
        
        params["width"] = _width
        params["height"] = _height
        params["quality"] = _quality
        params["background"] = _background
        params["output"] = _output

        return [self.client.call(method: Client.HTTPMethod.get.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)];
    }

    /**
     * Get File for View
     *
     * Get file content by its unique ID. This endpoint is similar to the download
     * method but returns with no  'Content-Disposition: attachment' header.
     *
     * @param String _fileId
     * @param String _as
     * @throws Exception
     * @return array
     */

    func getFileView(_fileId: String, _as: String = "") -> Array<Any> {
        var path: String = "/storage/files/{fileId}/view"

        path = path.replacingOccurrences(
          of: "{fileId}",
          with: _fileId
        )

                var params: [String: Any] = [:]
        
        params["as"] = _as

        return [self.client.call(method: Client.HTTPMethod.get.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)];
    }

}
