mutation {
    accountCreateRecovery(
        email: "email@example.com",
        url: "https://example.com"
    ) {
        id
        createdAt
        userId
        secret
        expire
    }
}
