query {
    storageGetFile(
        bucketId: "[BUCKET_ID]",
        fileId: "[FILE_ID]"
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
