mutation {
    databasesUpsertDocument(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        documentId: "<DOCUMENT_ID>",
        data: "{}",
        permissions: ["read("any")"],
        transactionId: "<TRANSACTION_ID>"
    ) {
        _id
        _sequence
        _collectionId
        _databaseId
        _createdAt
        _updatedAt
        _permissions
        data
    }
}
