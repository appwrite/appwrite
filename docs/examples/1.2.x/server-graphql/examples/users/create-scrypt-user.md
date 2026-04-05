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
        prefs {
            data
        }
    }
}
