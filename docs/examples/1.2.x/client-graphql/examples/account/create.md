mutation {
    accountCreate(
        userId: "[USER_ID]",
        email: "email@example.com",
        password: "password"
    ) {
        id
        createdAt
        updatedAt
        name
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