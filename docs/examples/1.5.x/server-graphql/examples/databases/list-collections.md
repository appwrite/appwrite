query {
    databasesListCollections(
        databaseId: "<DATABASE_ID>",
        queries: [],
        search: "<SEARCH>"
    ) {
        total
        collections {
            _id
            _createdAt
            _updatedAt
            _permissions
            databaseId
            name
            enabled
            documentSecurity
            attributes
            indexes {
                key
                type
                status
                error
                attributes
                orders
            }
        }
    }
}
