mutation {
    storageCreateFile(
        bucketId: "[BUCKET_ID]",
        fileId: "[FILE_ID]",
        file: null
    ) {
        id
        bucketId
        createdAt
        updatedAt
        permissions
        name
        signature
        mimeType
        sizeOriginal
        chunksTotal
        chunksUploaded
    }
}