mutation {
    tablesDBUpdatePolygonColumn(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        key: "",
        required: false,
        default: "",
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
        default
    }
}
