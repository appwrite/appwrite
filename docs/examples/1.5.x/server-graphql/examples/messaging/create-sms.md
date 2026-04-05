mutation {
    messagingCreateSms(
        messageId: "<MESSAGE_ID>",
        content: "<CONTENT>",
        topics: [],
        users: [],
        targets: [],
        draft: false,
        scheduledAt: ""
    ) {
        _id
        _createdAt
        _updatedAt
        providerType
        topics
        users
        targets
        scheduledAt
        deliveredAt
        deliveryErrors
        deliveredTotal
        data
        status
    }
}
