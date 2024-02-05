mutation {
    usersCreateTarget(
        userId: "[USER_ID]",
        targetId: "[TARGET_ID]",
        providerType: "email",
        identifier: "[IDENTIFIER]"
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
