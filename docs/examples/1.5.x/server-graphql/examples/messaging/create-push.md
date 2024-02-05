mutation {
    messagingCreatePush(
        messageId: "[MESSAGE_ID]",
        title: "[TITLE]",
        body: "[BODY]"
    ) {
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
