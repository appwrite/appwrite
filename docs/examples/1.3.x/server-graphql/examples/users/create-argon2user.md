mutation {
    usersCreateArgon2User(
        userId: "[USER_ID]",
        email: "email@example.com",
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
        prefs {
            data
        }
    }
}
