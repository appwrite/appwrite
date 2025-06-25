mutation {
    accountCreateMagicURLToken(
        userId: "<USER_ID>",
        email: "email@example.com",
        url: "https://example.com",
        phrase: false
    ) {
        _id
        _createdAt
        userId
        secret
        expire
        phrase
    }
}
