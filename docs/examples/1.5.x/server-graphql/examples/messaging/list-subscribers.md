query {
    messagingListSubscribers(
        topicId: "[TOPIC_ID]"
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
