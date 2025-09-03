mutation {
    tablesDBUpdateUrlColumn(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        key: "",
        required: false,
        default: "https://example.com",
        newKey: ""
    ) {
        key
        type
        status
        error
        required
        array
        _createdAt
        _updatedAt
        format
        default
    }
}
