mutation {
    messagingUpdateSMS(
        messageId: "[MESSAGE_ID]"
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
