mutation {
    databasesUpsertDocuments(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        documents: []
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
