mutation {
    databasesCreateIndex(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        key: "",
        type: "key",
        attributes: [],
        orders: []
    ) {
        key
        type
        status
        error
        attributes
        orders
    }
}
