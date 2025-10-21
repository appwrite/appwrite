mutation {
    databasesUpdateDocuments(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        data: "{}",
        queries: [],
        transactionId: "<TRANSACTION_ID>"
    ) {
        total
        documents {
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
}
