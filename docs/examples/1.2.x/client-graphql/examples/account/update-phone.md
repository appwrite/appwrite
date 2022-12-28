mutation {
    accountUpdatePhone(
        phone: "+12065550100",
        password: "password"
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
        prefs
    }
}
