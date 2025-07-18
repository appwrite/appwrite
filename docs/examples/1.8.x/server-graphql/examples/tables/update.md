mutation {
    tablesUpdate(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        name: "<NAME>",
        permissions: ["read("any")"],
        rowSecurity: false,
        enabled: false
    ) {
        _id
        _createdAt
        _updatedAt
        _permissions
        databaseId
        name
        enabled
        rowSecurity
        columns
        indexes {
            key
            type
            status
            error
            columns
            lengths
            orders
            _createdAt
            _updatedAt
        }
    }
}
