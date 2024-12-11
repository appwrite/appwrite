mutation {
    databasesCreateStringAttribute(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        key: "",
        size: 1,
        required: false
    ) {
        key
        type
        status
        error
        required
        size
    }
}
