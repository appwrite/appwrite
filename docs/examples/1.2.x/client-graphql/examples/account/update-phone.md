mutation {
    accountUpdatePhone(
        phone: "+12065550100",
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
