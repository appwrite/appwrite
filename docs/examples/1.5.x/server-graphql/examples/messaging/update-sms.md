mutation {
    messagingUpdateSms(
        messageId: "<MESSAGE_ID>",
        topics: [],
        users: [],
        targets: [],
        content: "<CONTENT>",
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
