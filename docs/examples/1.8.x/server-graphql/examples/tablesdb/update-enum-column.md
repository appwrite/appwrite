mutation {
    tablesDBUpdateEnumColumn(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        key: "",
        elements: [],
        required: false,
        default: "<DEFAULT>",
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
        elements
        format
        default
    }
}
