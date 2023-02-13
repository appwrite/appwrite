query {
    usersList {
        total
        users {
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
            prefs {
                data
            }
        }
    }
}
