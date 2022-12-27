mutation {
    usersUpdatePhone(
        userId: "[USER_ID]",
        number: "+12065550100"
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
