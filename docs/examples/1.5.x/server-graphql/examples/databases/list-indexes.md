query {
    databasesListIndexes(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        queries: []
    ) {
        total
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
