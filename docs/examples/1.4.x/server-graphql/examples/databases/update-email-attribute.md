mutation {
    databasesUpdateEmailAttribute(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        key: "",
        required: false,
        default: "email@example.com"
    ) {
        key
        type
        status
        error
        required
        format
    }
}
