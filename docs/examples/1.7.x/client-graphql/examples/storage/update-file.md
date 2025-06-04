mutation {
    storageUpdateFile(
        bucketId: "<BUCKET_ID>",
        fileId: "<FILE_ID>",
        name: "<NAME>",
        permissions: ["read("any")"]
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
