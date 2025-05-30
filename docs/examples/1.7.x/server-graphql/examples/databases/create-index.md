mutation {
    databasesCreateIndex(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        key: "",
        type: "key",
        attributes: [],
        orders: [],
        lengths: []
    ) {
        key
        type
        status
        error
        attributes
        lengths
        orders
        _createdAt
        _updatedAt
    }
}
