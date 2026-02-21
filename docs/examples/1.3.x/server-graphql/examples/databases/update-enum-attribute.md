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
        required
        elements
        format
    }
}
