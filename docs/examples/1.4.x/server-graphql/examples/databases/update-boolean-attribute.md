mutation {
    databasesUpdateBooleanAttribute(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        key: "",
        required: false,
        default: false
    ) {
        key
        type
        status
        error
        required
    }
}
