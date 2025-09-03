mutation {
    tablesDBUpdateRelationshipColumn(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        key: "",
        onDelete: "cascade",
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
        relatedTable
        relationType
        twoWay
        twoWayKey
        onDelete
        side
    }
}
