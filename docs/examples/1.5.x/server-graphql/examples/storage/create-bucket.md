mutation {
    storageCreateBucket(
        bucketId: "<BUCKET_ID>",
        name: "<NAME>",
        permissions: ["read("any")"],
        fileSecurity: false,
        enabled: false,
        maximumFileSize: 1,
        allowedFileExtensions: [],
        compression: "none",
        encryption: false,
        antivirus: false
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
