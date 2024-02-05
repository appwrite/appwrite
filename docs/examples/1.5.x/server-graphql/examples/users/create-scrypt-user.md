mutation {
    usersCreateScryptUser(
        userId: "[USER_ID]",
        email: "email@example.com",
        password: "password",
        passwordSalt: "[PASSWORD_SALT]",
        passwordCpu: 0,
        passwordMemory: 0,
        passwordParallel: 0,
        passwordLength: 0
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
