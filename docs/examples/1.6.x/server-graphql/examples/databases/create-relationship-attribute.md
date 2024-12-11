mutation {
    databasesCreateRelationshipAttribute(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        relatedCollectionId: "<RELATED_COLLECTION_ID>",
        type: "oneToOne",
        twoWay: false,
        key: "",
        twoWayKey: "",
        onDelete: "cascade"
    ) {
        key
        type
        status
        error
        required
        array
        relatedCollection
        relationType
        twoWay
        twoWayKey
        onDelete
        side
    }
}
