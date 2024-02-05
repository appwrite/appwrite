mutation {
    usersCreateToken(
        userId: "[USER_ID]"
    ) {
        _id
        _createdAt
        userId
        secret
        expire
        phrase
    }
}
