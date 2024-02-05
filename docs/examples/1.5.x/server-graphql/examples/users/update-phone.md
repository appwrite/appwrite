mutation {
    usersUpdatePhone(
        userId: "[USER_ID]",
        number: "+12065550100"
    ) {
        _id
        _createdAt
        _updatedAt
        name
        registration
        status
        labels
        passwordUpdate
        email
        phone
        emailVerification
        phoneVerification
        mfa
        totp
        prefs {
            data
        }
        targets {
            _id
            _createdAt
            _updatedAt
            name
            userId
            providerType
            identifier
        }
        accessedAt
    }
}
