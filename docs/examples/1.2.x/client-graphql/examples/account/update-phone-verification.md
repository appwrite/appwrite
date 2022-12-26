mutation {
    accountUpdatePhoneVerification(
        userId: "[USER_ID]",
        secret: "[SECRET]"
    ) {
        id
        createdAt
        userId
        secret
        expire
    }
}
