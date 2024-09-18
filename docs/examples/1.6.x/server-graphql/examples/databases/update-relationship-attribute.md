mutation {
    databasesUpdateRelationshipAttribute(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        key: "",
        onDelete: "cascade",
        newKey: ""
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
