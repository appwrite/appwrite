mutation {
    accountCreateChallenge(
        factor: "totp"
    ) {
        _id
        _createdAt
        userId
        expire
    }
}
