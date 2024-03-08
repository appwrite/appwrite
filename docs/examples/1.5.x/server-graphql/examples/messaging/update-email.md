mutation {
    messagingUpdateEmail(
        messageId: "<MESSAGE_ID>",
        topics: [],
        users: [],
        targets: [],
        subject: "<SUBJECT>",
        content: "<CONTENT>",
        draft: false,
        html: false,
        cc: [],
        bcc: [],
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
