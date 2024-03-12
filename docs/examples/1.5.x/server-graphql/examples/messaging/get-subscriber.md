query {
    messagingGetSubscriber(
        topicId: "<TOPIC_ID>",
        subscriberId: "<SUBSCRIBER_ID>"
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
        }
        userId
        userName
        topicId
        providerType
    }
}
