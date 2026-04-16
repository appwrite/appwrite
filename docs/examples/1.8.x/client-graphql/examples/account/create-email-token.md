mutation {
    accountCreateEmailToken(
        userId: "<USER_ID>",
        email: "email@example.com",
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
