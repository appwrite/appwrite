mutation {
    databasesCreateEnumAttribute(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        key: "",
        elements: [],
        required: false
    ) {
        key
        type
        status
        required
        elements
        format
    }
}