mutation {
    tablesDBCreateLineColumn(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        key: "",
        required: false,
        default: [[1, 2], [3, 4], [5, 6]]
    ) {
        key
        type
        status
        error
        required
        array
        _createdAt
        _updatedAt
        default
    }
}
