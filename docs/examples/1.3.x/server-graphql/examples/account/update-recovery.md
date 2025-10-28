mutation {
    accountUpdateRecovery(
        userId: "[USER_ID]",
        secret: "[SECRET]",
        password: "password",
        passwordAgain: "password"
    ) {
        _id
        _createdAt
        userId
        secret
        expire
    }
}
