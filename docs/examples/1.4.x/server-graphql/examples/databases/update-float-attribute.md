mutation {
    databasesUpdateFloatAttribute(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        key: "",
        required: false,
        min: 0,
        max: 0,
        default: 0
    ) {
        key
        type
        status
        error
        required
    }
}
