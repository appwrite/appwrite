mutation {
    accountCreatePhoneToken(
        userId: "<USER_ID>",
        phone: "+12065550100"
    ) {
        _id
        _createdAt
        userId
        secret
        expire
        phrase
    }
}
