mutation {
    accountCreateVerification(
        url: "https://example.com"
    ) {
        id
        createdAt
        userId
        secret
        expire
    }
}
