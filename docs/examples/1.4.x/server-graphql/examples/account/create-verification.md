mutation {
    accountCreateVerification(
        url: "https://example.com"
    ) {
        _id
        _createdAt
        userId
        secret
        expire
    }
}
