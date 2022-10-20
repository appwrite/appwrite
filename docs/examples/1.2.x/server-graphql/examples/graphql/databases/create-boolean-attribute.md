mutation {
    databasesCreateBooleanAttribute(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        key: ,
        required: false
    ) {
        key
        type
        status
        required
    }
}