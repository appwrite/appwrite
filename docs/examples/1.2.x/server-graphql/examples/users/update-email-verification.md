mutation {
    usersUpdateEmailVerification(
        userId: "[USER_ID]",
        emailVerification: false
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
