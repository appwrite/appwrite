mutation {
    databasesCreateDocument(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        documentId: "[DOCUMENT_ID]",
        data: "{}"
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