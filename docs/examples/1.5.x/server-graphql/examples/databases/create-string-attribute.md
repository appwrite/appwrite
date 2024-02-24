mutation {
    databasesCreateStringAttribute(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        key: "",
        size: 1,
        required: false,
        default: "<DEFAULT>",
        array: false,
        encrypt: false
    ) {
        key
        type
        status
        error
        required
        array
        size
        default
    }
}
