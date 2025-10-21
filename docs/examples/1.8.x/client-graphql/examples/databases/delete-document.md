mutation {
    databasesDeleteDocument(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        documentId: "<DOCUMENT_ID>",
        transactionId: "<TRANSACTION_ID>"
    ) {
        status
    }
}
