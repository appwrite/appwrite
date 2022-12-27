mutation {
    accountCreatePhoneSession(
        userId: "[USER_ID]",
        phone: "+12065550100"
    ) {
        id
        createdAt
        userId
        secret
        expire
    }
}
