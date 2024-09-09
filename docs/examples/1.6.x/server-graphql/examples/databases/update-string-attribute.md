mutation {
    databasesUpdateStringAttribute(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        key: "",
        required: false,
        default: "<DEFAULT>",
        size: 0,
        newKey: ""
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
