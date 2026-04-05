mutation {
    accountUpdatePhoneVerification(
        userId: "[USER_ID]",
        secret: "[SECRET]"
    ) {
        _id
        _createdAt
        userId
        secret
        expire
    }
}
