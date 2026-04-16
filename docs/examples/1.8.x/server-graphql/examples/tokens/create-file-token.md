mutation {
    tokensCreateFileToken(
        bucketId: "<BUCKET_ID>",
        fileId: "<FILE_ID>",
        expire: ""
    ) {
        _id
        _createdAt
        resourceId
        resourceType
        expire
        secret
        accessedAt
    }
}
