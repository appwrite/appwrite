mutation {
    databasesUpdateDatetimeAttribute(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        key: "",
        required: false,
        default: ""
    ) {
        key
        type
        status
        required
        format
    }
}
