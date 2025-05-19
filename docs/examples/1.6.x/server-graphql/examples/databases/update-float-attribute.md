mutation {
    databasesUpdateFloatAttribute(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        key: "",
        required: false,
        default: 0,
        min: 0,
        max: 0,
        newKey: ""
    ) {
        key
        type
        status
        error
        required
        array
        _createdAt
        _updatedAt
        min
        max
        default
    }
}
