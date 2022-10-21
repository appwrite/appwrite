mutation {
    accountCreatePhoneSession(
        userId: "[USER_ID]",
        phone: ""
    ) {
        id
        createdAt
        userId
        secret
        expire
    }
}