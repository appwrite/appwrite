mutation {
    databasesUpdateCollection(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        name: "[NAME]"
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
