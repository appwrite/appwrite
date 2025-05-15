mutation {
    tokensCreateFileToken(
        bucketId: "<BUCKET_ID>",
        fileId: "<FILE_ID>",
        expire: "",
        permissions: ["read("any")"]
    ) {
        _id
        _createdAt
        _permissions
        resourceId
        resourceType
        expire
        accessedAt
    }
}
