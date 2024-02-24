query {
    databasesList(
        queries: [],
        search: "<SEARCH>"
    ) {
        total
        databases {
            _id
            name
            _createdAt
            _updatedAt
            enabled
        }
    }
}
