query {
    storageGetFile(
        bucketId: "[BUCKET_ID]",
        fileId: "[FILE_ID]"
    ) {
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
