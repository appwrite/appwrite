mutation {
    usersUpdateTarget(
        userId: "<USER_ID>",
        targetId: "<TARGET_ID>",
        identifier: "<IDENTIFIER>",
        providerId: "<PROVIDER_ID>",
        name: "<NAME>"
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
