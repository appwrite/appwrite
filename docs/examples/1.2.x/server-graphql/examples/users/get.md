query {
    usersGet(
        userId: "[USER_ID]"
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
