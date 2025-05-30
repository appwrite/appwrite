mutation {
    databasesCreateDocuments(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        documents: []
    ) {
        total
        documents {
            _id
            _collectionId
            _databaseId
            _createdAt
            _updatedAt
            _permissions
            data
        }
    }
}
