mutation {
    accountCreateRecovery(
        email: "email@example.com",
        url: "https://example.com"
    ) {
        _id
        _createdAt
        userId
        secret
        expire
    }
}
