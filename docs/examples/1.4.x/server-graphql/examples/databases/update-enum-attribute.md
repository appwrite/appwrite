mutation {
    databasesUpdateEnumAttribute(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        key: "",
        elements: [],
        required: false,
        default: "[DEFAULT]"
    ) {
        key
        type
        status
        error
        required
        elements
        format
    }
}
