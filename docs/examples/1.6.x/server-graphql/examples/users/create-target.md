mutation {
    usersCreateTarget(
        userId: "<USER_ID>",
        targetId: "<TARGET_ID>",
        providerType: "email",
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
