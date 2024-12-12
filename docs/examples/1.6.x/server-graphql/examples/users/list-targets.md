query {
    usersListTargets(
        userId: "<USER_ID>",
        queries: []
    ) {
        total
        targets {
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
}
