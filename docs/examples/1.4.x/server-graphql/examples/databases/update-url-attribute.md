mutation {
    databasesUpdateUrlAttribute(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        key: "",
        required: false,
        default: "https://example.com"
    ) {
        key
        type
        status
        error
        required
        format
    }
}
