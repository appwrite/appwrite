mutation {
    databasesCreateIndex(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        key: "",
        type: "key",
        attributes: []
    ) {
        key
        type
        status
        attributes
    }
}