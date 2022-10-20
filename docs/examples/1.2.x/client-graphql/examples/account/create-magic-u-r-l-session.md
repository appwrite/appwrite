mutation {
    accountCreateMagicURLSession(
        userId: "[USER_ID]",
        email: "email@example.com"
    ) {
        id
        createdAt
        userId
        secret
        expire
    }
}