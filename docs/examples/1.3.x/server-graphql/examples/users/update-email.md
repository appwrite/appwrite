mutation {
    usersUpdateEmail(
        userId: "[USER_ID]",
        email: "email@example.com"
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
