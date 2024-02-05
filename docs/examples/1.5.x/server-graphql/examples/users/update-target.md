mutation {
    usersUpdateTarget(
        userId: "[USER_ID]",
        targetId: "[TARGET_ID]"
    ) {
        _id
        _createdAt
        _updatedAt
        name
        userId
        providerType
        identifier
    }
}
