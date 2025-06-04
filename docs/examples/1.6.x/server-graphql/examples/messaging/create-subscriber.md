mutation {
    messagingCreateSubscriber(
        topicId: "<TOPIC_ID>",
        subscriberId: "<SUBSCRIBER_ID>",
        targetId: "<TARGET_ID>"
    ) {
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
            expired
        }
        userId
        userName
        topicId
        providerType
    }
}
