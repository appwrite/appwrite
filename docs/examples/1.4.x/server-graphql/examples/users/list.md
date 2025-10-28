query {
    usersList {
        total
        users {
            _id
            _createdAt
            _updatedAt
            name
            registration
            status
            labels
            passwordUpdate
            email
            phone
            emailVerification
            phoneVerification
            prefs {
                data
            }
            accessedAt
        }
    }
}
