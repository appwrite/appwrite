mutation {
    databasesUpdate(
        databaseId: "[DATABASE_ID]",
        name: "[NAME]"
    ) {
        _id
        name
        _createdAt
        _updatedAt
    }
}
