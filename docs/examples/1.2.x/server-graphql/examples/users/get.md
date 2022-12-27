query {
    usersGet(
        userId: "[USER_ID]"
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
