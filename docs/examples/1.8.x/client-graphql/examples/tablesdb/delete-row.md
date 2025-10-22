mutation {
    tablesDBDeleteRow(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        rowId: "<ROW_ID>",
        transactionId: "<TRANSACTION_ID>"
    ) {
        status
    }
}
