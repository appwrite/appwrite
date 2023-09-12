query {
    storageGetBucket(
        bucketId: "[BUCKET_ID]"
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
