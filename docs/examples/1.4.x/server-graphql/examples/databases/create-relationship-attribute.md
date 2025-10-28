mutation {
    databasesCreateRelationshipAttribute(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        relatedCollectionId: "[RELATED_COLLECTION_ID]",
        type: "oneToOne"
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
