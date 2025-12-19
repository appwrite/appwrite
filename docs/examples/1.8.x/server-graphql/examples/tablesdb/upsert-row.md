mutation {
    tablesDBUpsertRow(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        rowId: "<ROW_ID>",
        data: "{\"username\":\"walter.obrien\",\"email\":\"walter.obrien@example.com\",\"fullName\":\"Walter O'Brien\",\"age\":33,\"isAdmin\":false}",
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
