mutation {
    messagingCreateSMS(
        messageId: "[MESSAGE_ID]",
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
