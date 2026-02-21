mutation {
    databasesUpdateRelationshipAttribute(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        key: ""
    ) {
        key
        type
        status
        error
        required
        relatedCollection
        relationType
        twoWay
        twoWayKey
        onDelete
        side
    }
}
