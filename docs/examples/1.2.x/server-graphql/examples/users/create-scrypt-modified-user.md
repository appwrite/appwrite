mutation {
    usersCreateScryptModifiedUser(
        userId: "[USER_ID]",
        email: "email@example.com",
        password: "password",
        passwordSalt: "[PASSWORD_SALT]",
        passwordSaltSeparator: "[PASSWORD_SALT_SEPARATOR]",
        passwordSignerKey: "[PASSWORD_SIGNER_KEY]"
    ) {
        id
        createdAt
        updatedAt
        name
        password
        hash
        hashOptions
        registration
        status
        passwordUpdate
        email
        phone
        emailVerification
        phoneVerification
        prefs
    }
}
