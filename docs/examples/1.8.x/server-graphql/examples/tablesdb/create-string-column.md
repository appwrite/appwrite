mutation {
    tablesDBCreateStringColumn(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        key: "",
        size: 1,
        required: false,
        default: "<DEFAULT>",
        array: false,
        encrypt: false
    ) {
        key
        type
        status
        error
        required
        array
        _createdAt
        _updatedAt
        size
        default
        encrypt
    }
}
