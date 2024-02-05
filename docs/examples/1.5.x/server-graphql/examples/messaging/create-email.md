mutation {
    messagingCreateEmail(
        messageId: "[MESSAGE_ID]",
        subject: "[SUBJECT]",
        content: "[CONTENT]"
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
