mutation {
    tablesDBUpsertRows(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        rows: [],
        transactionId: "<TRANSACTION_ID>"
    ) {
        total
        rows {
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
}
