mutation {
    tablesCreateIndex(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        key: "",
        type: "key",
        columns: [],
        orders: [],
        lengths: []
    ) {
        key
        type
        status
        error
        columns
        lengths
        orders
        _createdAt
        _updatedAt
    }
}
