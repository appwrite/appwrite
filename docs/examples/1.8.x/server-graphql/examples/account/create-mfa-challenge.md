mutation {
    accountCreateMFAChallenge(
        factor: "email"
    ) {
        _id
        _createdAt
        userId
        expire
    }
}
