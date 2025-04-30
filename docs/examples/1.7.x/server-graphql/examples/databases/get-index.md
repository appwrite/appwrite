query {
    databasesGetIndex(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        key: ""
    ) {
        key
        type
        status
        error
        attributes
        orders
        _createdAt
        _updatedAt
    }
}
