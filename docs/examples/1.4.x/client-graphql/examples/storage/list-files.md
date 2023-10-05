query {
    storageListFiles(
        bucketId: "[BUCKET_ID]"
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
