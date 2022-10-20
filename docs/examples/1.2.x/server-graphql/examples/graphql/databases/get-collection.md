query {
    databasesGetCollection(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]"
    ) {
        id
        createdAt
        updatedAt
        permissions
        databaseId
        name
        enabled
        documentSecurity
        attributes
        indexes
    }
}