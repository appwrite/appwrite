mutation {
    storageUpdateBucket(
        bucketId: "[BUCKET_ID]",
        name: "[NAME]"
    ) {
        id
        createdAt
        updatedAt
        permissions
        fileSecurity
        name
        enabled
        maximumFileSize
        allowedFileExtensions
        compression
        encryption
        antivirus
    }
}
