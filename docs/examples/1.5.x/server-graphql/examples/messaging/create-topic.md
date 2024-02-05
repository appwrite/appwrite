mutation {
    messagingCreateTopic(
        topicId: "[TOPIC_ID]",
        name: "[NAME]"
    ) {
        _id
        _createdAt
        _updatedAt
        name
        total
    }
}
