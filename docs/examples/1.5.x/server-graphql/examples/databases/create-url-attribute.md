mutation {
    databasesCreateUrlAttribute(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        key: "",
        required: false,
        default: "https://example.com",
        array: false
    ) {
        key
        type
        status
        error
        required
        array
        format
        default
    }
}
