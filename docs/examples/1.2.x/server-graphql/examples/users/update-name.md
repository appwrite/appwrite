mutation {
    usersUpdateName(
        userId: "[USER_ID]",
        name: "[NAME]"
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
