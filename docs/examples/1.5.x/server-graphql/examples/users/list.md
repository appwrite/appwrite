query {
    usersList(
        queries: [],
        search: "<SEARCH>"
    ) {
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
            labels
            passwordUpdate
            email
            phone
            emailVerification
            phoneVerification
            mfa
            prefs {
                data
            }
            targets {
                _id
                _createdAt
                _updatedAt
                name
                userId
                providerId
                providerType
                identifier
            }
            accessedAt
        }
    }
}
