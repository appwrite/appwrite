mutation {
    accountCreate2FAChallenge(
        factor: "totp"
    ) {
        _id
        _createdAt
        userId
        expire
    }
}
