mutation {
    tablesDBCreateIndex(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        key: "",
        type: "key",
        columns: [],
        orders: [],
        lengths: []
    ) {
        _id
        _createdAt
        _updatedAt
        key
        type
        status
        error
        columns
        lengths
        orders
    }
}
