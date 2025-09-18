mutation {
    accountCreateMfaChallenge(
        factor: "email"
    ) {
        _id
        _createdAt
        userId
        expire
    }
}
