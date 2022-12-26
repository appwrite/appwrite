query {
    databasesGetDocument(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        documentId: "[DOCUMENT_ID]"
    ) {
        id
        collectionId
        databaseId
        createdAt
        updatedAt
        permissions
        data
    }
}
