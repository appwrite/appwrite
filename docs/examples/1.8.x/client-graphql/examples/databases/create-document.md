mutation {
    databasesCreateDocument(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        documentId: "<DOCUMENT_ID>",
        data: "{\"username\":\"walter.obrien\",\"email\":\"walter.obrien@example.com\",\"fullName\":\"Walter O'Brien\",\"age\":30,\"isAdmin\":false}",
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
