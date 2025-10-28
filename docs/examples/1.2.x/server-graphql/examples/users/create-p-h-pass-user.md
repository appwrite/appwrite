mutation {
    usersCreatePHPassUser(
        userId: "[USER_ID]",
        email: "email@example.com",
        password: "password"
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
