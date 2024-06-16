mutation {
    accountCreatePushTarget(
        targetId: "<TARGET_ID>",
        identifier: "<IDENTIFIER>",
        providerId: "<PROVIDER_ID>"
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
