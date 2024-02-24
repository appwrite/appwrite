mutation {
    messagingCreateSms(
        messageId: "<MESSAGE_ID>",
        content: "<CONTENT>",
        topics: [],
        users: [],
        targets: [],
        status: "draft",
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
