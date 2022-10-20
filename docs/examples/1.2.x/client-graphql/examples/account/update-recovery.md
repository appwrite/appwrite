mutation {
    accountUpdateRecovery(
        userId: "[USER_ID]",
        secret: "[SECRET]",
        password: "password",
        passwordAgain: "password"
    ) {
        id
        createdAt
        userId
        secret
        expire
    }
}