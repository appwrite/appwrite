mutation {
    accountUpdatePushTarget(
        targetId: "<TARGET_ID>",
        identifier: "<IDENTIFIER>"
    ) {
        _id
        _createdAt
        _updatedAt
        name
        userId
        providerId
        providerType
        identifier
    }
}
