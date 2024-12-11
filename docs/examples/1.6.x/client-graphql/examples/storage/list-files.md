query {
    storageListFiles(
        bucketId: "<BUCKET_ID>",
        queries: [],
        search: "<SEARCH>"
    ) {
        total
        files {
            _id
            bucketId
            _createdAt
            _updatedAt
            _permissions
            name
            signature
            mimeType
            sizeOriginal
            chunksTotal
            chunksUploaded
        }
    }
}
