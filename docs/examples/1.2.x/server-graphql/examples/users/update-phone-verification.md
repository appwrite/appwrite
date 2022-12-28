mutation {
    usersUpdatePhoneVerification(
        userId: "[USER_ID]",
        phoneVerification: false
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
        prefs
    }
}
