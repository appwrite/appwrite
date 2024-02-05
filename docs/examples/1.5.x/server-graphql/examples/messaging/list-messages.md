query {
    messagingListMessages {
        total
        messages {
            _id
            _createdAt
            _updatedAt
            providerType
            topics
            users
            targets
            deliveredTotal
            data
            status
        }
    }
}
