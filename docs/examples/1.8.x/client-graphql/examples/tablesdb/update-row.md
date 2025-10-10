mutation {
    tablesDBUpdateRow(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        rowId: "<ROW_ID>",
        data: "{}",
        permissions: ["read("any")"],
        transactionId: "<TRANSACTION_ID>"
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
