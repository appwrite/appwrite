query {
    tokensList(
        bucketId: "<BUCKET_ID>",
        fileId: "<FILE_ID>",
        queries: []
    ) {
        total
        tokens {
            _id
            _createdAt
            resourceId
            resourceInternalId
            resourceType
            expire
        }
    }
}
