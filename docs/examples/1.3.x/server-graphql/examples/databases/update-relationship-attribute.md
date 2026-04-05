mutation {
    databasesUpdateRelationshipAttribute(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        key: ""
    ) {
        key
        type
        status
        required
        relatedCollection
        relationType
        twoWay
        twoWayKey
        onDelete
        side
    }
}
