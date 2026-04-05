query {
    messagingListMessages(
        queries: [],
        search: "<SEARCH>"
    ) {
        total
        messages {
            _id
            _createdAt
            _updatedAt
            providerType
            topics
            users
            targets
            scheduledAt
            deliveredAt
            deliveryErrors
            deliveredTotal
            data
            status
        }
    }
}
