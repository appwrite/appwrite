mutation {
    databasesUpdateCollection(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        name: "[NAME]"
    ) {
        _id
        _createdAt
        _updatedAt
        _permissions
        databaseId
        name
        enabled
        documentSecurity
        attributes
        indexes
    }
}
