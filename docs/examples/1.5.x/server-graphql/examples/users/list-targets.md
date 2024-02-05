query {
    usersListTargets(
        userId: "[USER_ID]"
    ) {
        total
        targets {
            _id
            _createdAt
            _updatedAt
            name
            userId
            providerType
            identifier
        }
    }
}
