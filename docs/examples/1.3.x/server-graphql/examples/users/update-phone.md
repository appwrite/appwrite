mutation {
    usersUpdatePhone(
        userId: "[USER_ID]",
        number: "+12065550100"
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
