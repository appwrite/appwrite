mutation {
    accountCreateChallenge(
        provider: "totp"
    ) {
        _id
        _createdAt
        userId
        expire
    }
}
