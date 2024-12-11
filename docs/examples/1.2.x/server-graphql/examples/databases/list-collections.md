query {
    databasesListCollections(
        databaseId: "[DATABASE_ID]"
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
                attributes
            }
        }
    }
}
