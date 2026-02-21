query {
    databasesGet(
        databaseId: "[DATABASE_ID]"
    ) {
        _id
        name
        _createdAt
        _updatedAt
        enabled
    }
}
