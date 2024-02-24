query {
    messagingListSubscribers(
        topicId: "<TOPIC_ID>",
        queries: [],
        search: "<SEARCH>"
    ) {
        total
        subscribers {
            _id
            _createdAt
            _updatedAt
            targetId
            target {
                _id
                _createdAt
                _updatedAt
                name
                userId
                providerId
                providerType
                identifier
            }
            userId
            userName
            topicId
            providerType
        }
    }
}
