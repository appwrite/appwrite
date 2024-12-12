mutation {
    databasesCreateBooleanAttribute(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        key: "",
        required: false,
        default: false,
        array: false
    ) {
        key
        type
        status
        error
        required
        array
        default
    }
}
