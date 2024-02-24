mutation {
    messagingUpdateSms(
        messageId: "<MESSAGE_ID>",
        topics: [],
        users: [],
        targets: [],
        content: "<CONTENT>",
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
