query {
    messagingGetTopic(
        topicId: "<TOPIC_ID>"
    ) {
        _id
        _createdAt
        _updatedAt
        name
        emailTotal
        smsTotal
        pushTotal
        subscribe
    }
}
