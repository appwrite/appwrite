mutation {
    accountUpdateRecovery(
        userId: "<USER_ID>",
        secret: "<SECRET>",
        password: ""
    ) {
        _id
        _createdAt
        userId
        secret
        expire
        phrase
    }
}
