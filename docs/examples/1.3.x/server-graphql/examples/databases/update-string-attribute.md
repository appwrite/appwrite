mutation {
    databasesUpdateStringAttribute(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        key: "",
        required: false,
        default: "[DEFAULT]"
    ) {
        key
        type
        status
        required
        size
    }
}
