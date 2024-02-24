mutation {
    messagingCreateTopic(
        topicId: "<TOPIC_ID>",
        name: "<NAME>",
        subscribe: ["any"]
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
