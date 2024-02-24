mutation {
    usersCreateToken(
        userId: "<USER_ID>",
        length: 4,
        expire: 60
    ) {
        _id
        _createdAt
        userId
        secret
        expire
        phrase
    }
}
