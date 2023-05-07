mutation {
    usersUpdatePhoneVerification(
        userId: "[USER_ID]",
        phoneVerification: false
    ) {
        _id
        _createdAt
        _updatedAt
        name
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
