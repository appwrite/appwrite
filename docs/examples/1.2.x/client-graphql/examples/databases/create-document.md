mutation {
    databasesCreateDocument(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        documentId: "[DOCUMENT_ID]",
        data: "{}"
    ) {
        _id
        _collectionId
        _databaseId
        _createdAt
        _updatedAt
        _permissions
        data
    }
}
