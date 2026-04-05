mutation {
    storageUpdateBucket(
        bucketId: "[BUCKET_ID]",
        name: "[NAME]"
    ) {
        _id
        _createdAt
        _updatedAt
        _permissions
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
