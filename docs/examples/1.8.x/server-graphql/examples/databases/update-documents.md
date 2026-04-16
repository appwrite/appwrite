mutation {
    databasesUpdateDocuments(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        data: "{\"username\":\"walter.obrien\",\"email\":\"walter.obrien@example.com\",\"fullName\":\"Walter O'Brien\",\"age\":33,\"isAdmin\":false}",
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
