mutation {
    usersCreateScryptModifiedUser(
        userId: "<USER_ID>",
        email: "email@example.com",
        password: "password",
        passwordSalt: "<PASSWORD_SALT>",
        passwordSaltSeparator: "<PASSWORD_SALT_SEPARATOR>",
        passwordSignerKey: "<PASSWORD_SIGNER_KEY>",
        name: "<NAME>"
    ) {
        _id
        _createdAt
        _updatedAt
        name
        password
        hash
        hashOptions
        registration
        status
        labels
        passwordUpdate
        email
        phone
        emailVerification
        phoneVerification
        mfa
        prefs {
            data
        }
        targets {
            _id
            _createdAt
            _updatedAt
            name
            userId
            providerId
            providerType
            identifier
        }
        accessedAt
    }
}
