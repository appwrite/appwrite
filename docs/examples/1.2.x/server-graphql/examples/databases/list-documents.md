query {
    databasesListDocuments(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]"
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
