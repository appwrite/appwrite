mutation {
    accountCreateMagicURLToken(
        userId: "[USER_ID]",
        email: "email@example.com"
    ) {
        _id
        _createdAt
        userId
        secret
        expire
        phrase
    }
}
