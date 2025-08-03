mutation {
    gridsUpsertRows(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        rows: []
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
