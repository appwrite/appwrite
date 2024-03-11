query {
    databasesGetCollection(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>"
    ) {
        _id
        _createdAt
        _updatedAt
        _permissions
        databaseId
        name
        enabled
        documentSecurity
        attributes
        indexes {
            key
            type
            status
            error
            attributes
            orders
        }
    }
}
