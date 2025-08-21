mutation {
    tablesDBDecrementRowColumn(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        rowId: "<ROW_ID>",
        column: "",
        value: 0,
        min: 0
    ) {
        _id
        _sequence
        _tableId
        _databaseId
        _createdAt
        _updatedAt
        _permissions
        data
    }
}
